package in.aushadhbox.sync;

import java.nio.charset.Charset;
import java.nio.charset.StandardCharsets;
import java.nio.file.*;
import java.nio.file.attribute.FileTime;
import java.util.*;

/**
 * Tests without any test library (so they run anywhere with only a JDK):
 *   javac -d build src/main/java/in/aushadhbox/sync/*.java src/test/java/in/aushadhbox/sync/*.java
 *   java -cp build in.aushadhbox.sync.SyncAgentTest
 */
public final class SyncAgentTest {
    static int pass, fail;

    static void ok(boolean c, String msg) { if (c) { pass++; System.out.println("  ✓ " + msg); } else { fail++; System.out.println("  ✗ " + msg); } }

    /** A fake server: records uploads, can pretend to be offline or to reject a file. */
    static final class FakeServer implements SyncAgent.Uploader {
        final List<String> files = new ArrayList<>();
        final List<String> bodies = new ArrayList<>();
        boolean offline;
        int rejectStatus;
        @Override public Result upload(String csv, boolean full, String fileName, String sha) {
            if (offline) return new Result(false, false, 0, "ConnectException");
            if (rejectStatus > 0) return new Result(false, true, rejectStatus, "{\"error\":\"bad_csv\"}");
            if (!sha.equals(SyncAgent.sha256(csv.getBytes(StandardCharsets.UTF_8)))) return new Result(false, false, 422, "corrupt");
            files.add(fileName); bodies.add(csv);
            return new Result(true, false, 200, "{\"ok\":true,\"rows\":" + (csv.lines().count() - 1) + "}");
        }
    }

    static SyncAgent.Config config(Path dir, Path folder) throws Exception {
        Properties p = new Properties();
        p.setProperty("server", "http://127.0.0.1:8090");
        p.setProperty("token", "abs_" + "a1".repeat(24));
        p.setProperty("folder", folder.toString());
        p.setProperty("settleSeconds", "1");
        return new SyncAgent.Config(p, dir);
    }

    static Path old(Path f) throws Exception { Files.setLastModifiedTime(f, FileTime.fromMillis(System.currentTimeMillis() - 60_000)); return f; }

    public static void main(String[] args) throws Exception {
        Path dir = Files.createTempDirectory("abs-sync-test"), exports = Files.createDirectories(dir.resolve("exports"));
        String csv = "Item Name,Batch,Expiry,Qty,MRP\nDolo 650,DL123,08/27,40,30.91\nDr. Reddy's Omez 20,OM9,05/2027,12,61.50\n";

        System.out.println("Uploading exports");
        FakeServer srv = new FakeServer();
        SyncAgent a = new SyncAgent(config(dir, exports), srv);
        old(Files.writeString(exports.resolve("closing_stock.csv"), csv));
        Files.writeString(exports.resolve("notes.txt"), "not an export");
        ok(a.runOnce() == 1 && srv.files.equals(List.of("closing_stock.csv")), "new CSV export is uploaded, other files ignored");
        ok(srv.bodies.get(0).contains("Dr. Reddy's Omez 20"), "content arrives unchanged");
        ok(a.runOnce() == 0, "an unchanged file is not uploaded again");
        old(Files.writeString(exports.resolve("closing_stock.csv"), csv + "Crocin 500,CR1,01/28,5,22\n"));
        ok(a.runOnce() == 1 && srv.bodies.get(1).contains("Crocin"), "a changed export is uploaded again");

        System.out.println("Offline and retries");
        srv.offline = true;
        old(Files.writeString(exports.resolve("evening.csv"), csv));
        ok(a.runOnce() == 0, "offline: nothing uploaded");
        try (var ds = Files.newDirectoryStream(dir.resolve("aushadhbox-sync-data/queue"), "*.q")) { ok(ds.iterator().hasNext(), "the export waits in the queue"); }
        srv.offline = false;
        SyncAgent b = new SyncAgent(config(dir, exports), srv);   // e.g. after the computer restarts
        Path stateFile = dir.resolve("aushadhbox-sync-data/state.properties");
        String st = Files.readString(stateFile).replaceAll("retryAt=\\d+", "retryAt=0");
        Files.writeString(stateFile, st);
        b = new SyncAgent(config(dir, exports), srv);
        ok(b.runOnce() == 1 && srv.files.get(srv.files.size() - 1).equals("evening.csv"), "after the internet returns (and a restart) the queued export is sent");
        ok(SyncAgent.backoffSeconds(1) == 30 && SyncAgent.backoffSeconds(3) == 120 && SyncAgent.backoffSeconds(20) == 1800, "back-off 30 s, doubling, capped at 30 minutes");

        System.out.println("Bad files");
        srv.rejectStatus = 400;
        old(Files.writeString(exports.resolve("broken.csv"), "x,y\n1,2\n"));
        b.runOnce();
        try (var ds = Files.newDirectoryStream(dir.resolve("aushadhbox-sync-data/queue"), "*.rejected")) { ok(ds.iterator().hasNext(), "a file the server rejects is set aside and does not block the queue"); }
        srv.rejectStatus = 0;
        old(Files.writeString(exports.resolve("header_only.csv"), "Item,Qty\n"));
        int before = srv.files.size(); b.runOnce();
        ok(srv.files.size() == before, "a file with no rows is skipped");

        System.out.println("Encodings");
        Path w = exports.resolve("w1252.bin");
        Files.write(w, "Item,Qty\nCafé syrup,2\n".getBytes(Charset.forName("windows-1252")));
        ok(SyncAgent.readAsUtf8(w).contains("Café syrup"), "Windows-1252 (old Marg / Excel) converted");
        byte[] u16 = "Item,Qty\nDolo,1\n".getBytes(StandardCharsets.UTF_16LE);
        byte[] bom = new byte[u16.length + 2]; bom[0] = (byte) 0xFF; bom[1] = (byte) 0xFE; System.arraycopy(u16, 0, bom, 2, u16.length);
        Files.write(w, bom);
        ok(SyncAgent.readAsUtf8(w).startsWith("Item,Qty"), "UTF-16 with BOM (Tally) converted");
        Files.write(w, ("﻿Item,Qty\n").getBytes(StandardCharsets.UTF_8));
        ok(SyncAgent.readAsUtf8(w).startsWith("Item"), "UTF-8 BOM removed");

        System.out.println("Settings and safety");
        ok(SyncAgent.Json.str("a\"b\\c\nd\u0001").equals("\"a\\\"b\\\\c\\nd\\u0001\""), "JSON escaping");
        Properties bad = new Properties(); bad.setProperty("server", "http://pharmacy.example.in"); bad.setProperty("token", "abs_" + "a1".repeat(24)); bad.setProperty("folder", "x");
        boolean refused = false; try { new SyncAgent.Config(bad, dir); } catch (IllegalArgumentException e) { refused = true; }
        ok(refused, "plain http to a real server is refused (the token is a secret)");
        bad.setProperty("server", "https://pharmacy.example.in"); bad.setProperty("token", "wrong");
        refused = false; try { new SyncAgent.Config(bad, dir); } catch (IllegalArgumentException e) { refused = true; }
        ok(refused, "a mistyped token is caught at start");
        ok(SyncAgent.sha256("abc".getBytes()).equals("ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"), "SHA-256");

        System.out.println("\n" + pass + " passed, " + fail + " failed");
        System.exit(fail == 0 ? 0 : 1);
    }
}
