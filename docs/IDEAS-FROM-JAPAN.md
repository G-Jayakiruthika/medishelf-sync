# Ideas from Japan (お薬手帳 apps and pharmacy practice)

Japan has had family medication notebooks (*okusuri techo*) for decades, and today most pharmacies offer an electronic version as a phone app. The Ministry of Health describes what these apps do: keep the medicine history, record blood pressure and blood sugar to share with doctors and pharmacists, look up medicine information, and connect with the national records. Pharmacy chains add sending a photo of the prescription before arriving, family management and emergency access to the medicine history. Since 2020 Japanese law has also required pharmacists to **follow up** with patients after dispensing when needed (服薬フォローアップ): they check how the patient is doing on a new medicine, look for side effects, and pass problems to the doctor.

AushadhBox adapts these ideas for Indian families:

| Japanese practice | In AushadhBox |
|---|---|
| Electronic お薬手帳: medicine history in your pocket | Cabinet, Notebook, printable doctor report (already there) |
| Health records (血圧・血糖) shared with the doctor | **Health diary**: blood pressure, sugar (fasting / after a meal), weight, temperature, oxygen, pulse. Each reading is checked against widely used home ranges (normal, low, high, needs attention now). The chart shows the trend, and a reading that needs attention alerts the family. |
| Pharmacist follow-up after dispensing (服薬フォローアップ) | **Follow-up check-ins**: on day 3 and day 10 of every new daily medicine, the app asks "how is it going?": better, same, worse or side effect, with an optional note. A problem alerts the family and moves the next check-in closer. All answers go on the visit sheet. |
| Bringing the notebook to the doctor; leftover-medicine review (残薬調整) | **Doctor visits** with reminders the evening before and 2 hours before, and a **visit sheet**: current medicines, tablets left and days they last, doses taken in 30 days, follow-up answers, skipped doses and why, readings (latest, average, out of range), safety alerts and the questions to ask. Print or share it. |
| Sending the prescription photo to the pharmacy (処方箋送信) | **My pharmacy**: save the pharmacy's WhatsApp number, and the shopping list is sent there in one tap. |
| Notebook used in disasters (lessons from the 2011 earthquake) | **Emergency card**: blood group, allergies, conditions, medicines and an emergency contact for each person, with a QR code that holds the same text. It works without internet (saved on the phone), opens from the home-screen shortcut, and can be saved as a lock-screen image. |
| Watch-over services for older people living alone (見守り) | **Watch-over**: if someone with their own phone or a pillbox shows no sign of life (no tick, no lid opening, app not opened, no reading) for 12, 24 or 48 hours, the family is told once a day. |
| Medicine calendar pockets (お薬カレンダー) | **Printable weekly calendar**: 7 days × morning/afternoon/evening/bedtime with tick boxes. |

## Round 2: household apps (harmo, Denso Wave, EPARK, おくすり連絡帳)

These Japanese apps go further than a notebook: the pharmacy itself fills the family's app, and most of the record keeping happens by itself.

| Japanese app | What it does | In AushadhBox |
|---|---|---|
| **harmo** (started by Sony): an IC card or QR the patient shows at the pharmacy; the pharmacy's system records what it gives, up to 10 family members | Medicines enter the family record without anyone typing | **AushadhBox card**: one QR (or 12-letter code) per person. The pharmacy scans it in **AushadhBox Pharmacy** (`/pharmacy`) and the medicines appear in the family's app with dose times, course length and pack count, so reminders, refill alerts and the day-3 / day-10 check-ins start by themselves. |
| **Denso Wave IoT medicine case**: the case notices which packet is taken, the family sees it remotely, Apple Watch reminders | Taking a dose is recorded without typing | Smart pillbox (round 1) plus **Smartwatch**: the watch page with one big Taken button, and a **tap-to-tick link** for a Siri Shortcut (Apple Watch), Google Assistant or Bixby routine, an NFC sticker or a printed QR on the box. Works with any watch that shows phone notifications, not only Apple. |
| **EPARK お薬手帳**: unlimited family members, records added by themselves, blood pressure and temperature graphs, alarms, vaccinations | One app for the whole household | Already: family members, health diary, alarms. New: **vaccination schedule** for children (India's UIP, made from the date of birth, with reminders), **Bluetooth meters** (BP, sugar, thermometer, scale, oximeter) that save readings by themselves, **import from watches and health apps** (Apple Health, Samsung Health, Google Fit, Fitbit), lab results (HbA1c, cholesterol, haemoglobin) and a **streak** for doses taken. |
| **おくすり連絡帳** (KAKEHASHI): QR for prescription and over-the-counter medicines, family sharing, interaction checks, linked to the pharmacy | The pharmacist sees the full picture, and the family can ask the pharmacy | **Safety check at the counter**: before handing over, the pharmacy runs duplicates, risky pairs and allergies against everything the person takes. **Refill requests** ("please keep these ready") go to a pharmacy the card was used at; its Ready answer comes back as a notification. **Barcodes**: a pack scanned once is recognised for the next family. **Medical expenses** (bills add themselves, CSV for tax) and a **24-hour doctor link**. |

### Privacy: the family decides

The user asked for this explicitly, so it is built into every step:
- The card is **off** until the family switches it on, separately for each person and for two things: *pharmacies may add the medicines they give* and *pharmacies may see medicines, allergies and check-ins*.
- The QR holds only a random code, never a name or medicines. A lost card is replaced with **New card**; the old code stops working at once.
- Only pharmacies whose drug licence the admin has checked (**Pharmacies** in the admin dashboard) can scan cards. A pharmacy sees a shortened name (Lakshmi N.).
- Without view permission, the safety check still runs, but the names of the family's other medicines are hidden from the pharmacy ("a medicine they already take").
- Every scan, look, addition and refill update is listed in the family's app with the pharmacy's name and time. The family can **block** a pharmacy at any time.
- The tap-to-tick and doctor links are long secret links that can be stopped from the app. Opening the tick link never ticks by itself (link previews and bots can't tick); it needs a tap or a POST from the shortcut.

### Using AushadhBox Pharmacy as its own project
The pharmacy portal is now its own product in `pharmacy-platform/` (own server, own database, own sign-in), linked to the family app by signed calls. The old `/pharmacy` address forwards there.

## Round 3: what we had missed

| Japanese practice | In AushadhBox |
|---|---|
| Refill prescriptions (リフィル処方箋, from 2022): one prescription used up to 3 times | **Repeat prescriptions** in Care+: doctor, date, valid-until and refills allowed; "order refill" sends the medicines with the prescription to a linked pharmacy, which sees the refill count. |
| Sending the prescription ahead and picking up at a set time (EPARK くすりの窓口) | **Pickup time** on every order; the pharmacy portal shows "customer comes at 6:30 pm: have it ready". |
| Dose packing (一包化) for older people | **Pack each dose time in its own pouch** on the order; the pharmacy prints colour-coded pouch labels per day and dose time. |
| Home visits by pharmacists (在宅訪問, 居宅療養管理指導) | **Pharmacist home visit** on a delivery order, and a **medicine review** request. |
| Polypharmacy review (ポリファーマシー対策, 減薬) | **Medicine review**: flags people with many daily medicines and medicines best avoided in older people (Beers-style list), with what to ask the doctor. |
| Food and drink interactions in the notebook (grapefruit, alcohol, milk) | **Food and drink cautions** per medicine, matched to Indian food and drink (tea, milk, alcohol, grapefruit, leafy greens). |
| Maternal and Child Health Handbook (母子健康手帳) | **Mother and child**: India's MCP card check-ups, PMSMA day, IFA tablets, danger signs, growth; vaccinations were already there. |
| Points from pharmacy apps (EPARK points, d-point) for healthy actions | **Health points** → vouchers at any network pharmacy, paid back to the shop in the monthly network statement. |
| Watch-over with home sensors (見守りセンサー) | Door / motion / power **life sensors** count as a sign of life. |

## What was not copied, and why
- **The national record link (MynaPortal)**: the Indian equivalent (ABHA / ABDM health records) needs registration as a health information user. It is a good next step once the business is registered.
- **Chat with a pharmacist**: that needs pharmacists on the team. "My pharmacy" on WhatsApp covers the common case.
- **The pharmacy QR standard (JAHIS)**: Indian pharmacies don't print a standard QR. The bill and prescription reader (photo → list) and the AushadhBox card do the same job.
- **NFC IC cards** (harmo's first version): Indian families carry phones, not IC cards. A QR on the phone or on a printed wallet card works with any pharmacy phone camera.

## Sources
- Ministry of Health, Labour and Welfare: electronic okusuri techo — https://www.mhlw.go.jp/stf/seisakunitsuite/bunya/e-okusuritecho.html
- PHC Medicom: features of medication-management apps — https://www.phchd.com/jp/medicom/electronic-prescription/column/patient/medication-management/
- PHC Medicom Park: medication follow-up in pharmacies — https://www.phchd.com/jp/medicom/park/idea/ph-management-medication-followup
- Healthcare IT News: Japan to pilot electronic medication notebooks — https://www.healthcareitnews.com/news/asia/japan-pilot-electronic-medication-notebooks
- Yakuji Nippo: harmo, the card-based electronic medication notebook — https://www.yakuji.co.jp/entry48563.html
- MONOist: Sony's electronic medication notebook with contactless cards — https://monoist.itmedia.co.jp/mn/spv/1504/10/news004.html
- MONOist: Denso Wave IoT medication case — https://monoist.itmedia.co.jp/mn/articles/2112/15/news021.html
- EPARK お薬手帳 (Kusuri no Madoguchi) — https://www.kusurinomadoguchi.com/okusuritecho
- おくすり連絡帳 on the App Store — https://apps.apple.com/JP/app/id1594523400
