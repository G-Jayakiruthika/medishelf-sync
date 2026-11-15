# AushadhBox sync agent (Java)

Keeps a shop's stock on AushadhBox Pharmacy in step with its billing software, with no typing.
Most Indian pharmacies already bill in **Marg ERP, GoFrugal, Medeil or Tally**. These can save the closing stock as a
CSV file every hour. The agent watches that folder and uploads each new export, so:

- customers see "in stock / few left" before they travel to the shop;
- linked pharmacies can find a medicine you have;
- the nightly analytics (`../analytics`) can suggest reorders and warn about stock that may expire.

The agent uses only the standard JDK. A 14 KB `.jar`, no installer, no libraries.

## What it does
1. Every 5 minutes it looks in the export folder for files matching the pattern (default `*.csv`).
2. It skips files that haven't changed (SHA-256) and waits until a new file has stopped growing (the billing software may still be writing it).
3. It converts the file to UTF-8 (Tally writes UTF-16, old Marg and Excel write Windows-1252), puts it in a queue folder and uploads it over HTTPS with the shop's token.
4. **No internet?** Files wait in the queue. Retries start after 30 seconds and back off to every 30 minutes. They survive a restart.
5. A file the server rejects (for example a wrong report with no item and quantity columns) is set aside as `.rejected` so it doesn't block later uploads. The reason is in `sync.log`.

The server matches columns by name (item name, batch, expiry, quantity, MRP, rate, barcode, schedule…), never sells
above MRP (a higher rate is capped and counted as `priceCapped`), and logs every upload in the shop's audit log.

## Install on the billing computer (Windows)
1. Install Java 17 or newer (for example Eclipse Temurin JRE), if it isn't there already.
2. Copy `aushadhbox-sync.jar` and `agent.example.properties` to `C:\AushadhBoxSync\`. Rename the settings file to `sync.properties`.
3. In the portal: **Stock → Billing software sync → New token**. Paste it into `sync.properties` with the export folder.
4. Test once: `java -jar aushadhbox-sync.jar sync.properties --once`. It should say `server says: {"ok":true,"branch":"…"}`.
5. Keep it running. Either:
   - **Task Scheduler:** run `java -jar C:\AushadhBoxSync\aushadhbox-sync.jar C:\AushadhBoxSync\sync.properties --once` every 15 minutes; or
   - **as a service** with [NSSM](https://nssm.cc): `nssm install AushadhBoxSync "C:\Program Files\Eclipse Adoptium\jre-17\bin\java.exe" -jar C:\AushadhBoxSync\aushadhbox-sync.jar C:\AushadhBoxSync\sync.properties`.

On Linux, use a systemd unit or cron with `--once`.

## Build and test
```
./build.sh            # Linux / macOS
build.bat             # Windows
mvn package           # optional, same result
```
The tests (`src/test`) use a fake server. They cover first upload, unchanged files, changed files, offline queueing,
retry after a restart, back-off, rejected files, empty files, the three encodings, JSON escaping, refusing plain
`http://` to a real server, and catching a mistyped token.

## Security
- The token is per computer, stored on the server only as a SHA-256 hash, and can be revoked in the portal.
- HTTPS is required (only `http://localhost` is allowed, for testing).
- The upload carries a SHA-256 of the file; the server refuses damaged uploads (`422 corrupt`), and the agent retries.
- Only stock leaves the shop computer: item, batch, expiry, quantity, MRP, rate. No customer or sales data.
