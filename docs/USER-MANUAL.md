# AushadhBox — User manual

A simple guide for families. The same help is on the website at **/help**.

## 1. Get started
1. Open the website and tap **Start free**.
2. Enter your name, e-mail and a password (8+ characters), tick the privacy box, tap **Create account**.
3. A short tour explains the coloured handles. Tap **Let's start** to scan your first medicine.
4. **Add to home screen** so it opens like an app: Android Chrome → menu ⋮ → *Add to Home screen*; iPhone Safari → Share → *Add to Home Screen*.

Change the language any time with the language menu at the top. The voice assistant uses the same language.

## 2. Add medicines
You rarely need to type anything. One scan button handles everything: it works out by itself whether the photo is a medicine strip, a doctor's prescription or a pharmacy bill.
- **Prescription or pharmacy bill:** photograph it (or paste its text under *Have the prescription as text?*). Every medicine on it appears in one list with its times, number of days and tablets already filled in. It understands doctor shorthand (1-0-1, OD, BD, TDS, HS, SOS, "x 5 days", after food) and Hindi and Tamil words. A pharmacy bill also gives batch number, expiry and quantity. Untick anything that isn't a medicine, choose who it is for and tap **Save**.
- **Usual schedule:** if the paper doesn't say when to take a medicine, the app fills in the usual times for that ingredient (for example Metformin morning and night after food, Azithromycin once a day for 3 days) and marks it *usual*. Always follow your own doctor's prescription if it is different; tap *Clear* to remove it.
- **Tablet count from the pack:** the number of tablets is filled in from the pack size (10's, 15's) or from the course length.
- **Say or type one sentence:** in *Quick add*, type or tap 🎤 and say, for example, "Amma's Telma 40 every morning" or "Add Dolo 650 twice a day for 3 days". The voice assistant (Ask) understands the same sentences.
- **Share from WhatsApp or the gallery:** on Android, after installing the app, choose *Share → AushadhBox* on a photo of a prescription or strip. It opens and scans straight away.
- Expiry is optional. Medicines without one show *Expiry not added*; add it later from the pack.
- **Scan:** tap **Scan medicine** → *Take or upload photo* (or *Use live camera*). Point at the back of the strip where B.No., Mfg. and Exp. are printed, or at the QR code. Check what was found, choose who it is for, tap **Save to cabinet**.
- **Type:** tap **Add by hand**, start typing the name and pick from the list.
- If the scan misreads a date, correct it before saving.

## 3. Read your cabinet
| Handle | Meaning | What to do |
|---|---|---|
| Green | Safe (more than 90 days left) | Nothing |
| Amber | Use soon (90 days or less) | Use it first; buy a fresh pack if needed |
| Red | Expired | Do not use. Return it to a pharmacy take-back point. |

Tap a person's name at the top to see only their medicines. Tap ✕ on a drawer to remove a medicine.

## 3a. Safety check
Every time a medicine is added, AushadhBox checks each person's medicines and shows a **Safety check** box at the top of the cabinet if it finds:
- the **same medicine in two brands** (for example Dolo 650 and Crocin are both paracetamol): taking both is a double dose;
- **two medicines of the same kind** (for example two painkillers, or two blood-pressure medicines of the same type);
- **well-known risky combinations** (for example a painkiller with a blood thinner);
- medicines that must be **taken hours apart** but are set for the same time (for example an antibiotic and calcium);
- a medicine that matches the person's **allergy** written in the Notebook.
It never changes anything by itself. Show it to your doctor or pharmacist.

**AI helper (optional).** In Account you can switch on the AI helper. Then the Ask button answers questions about your family's medicines in your language ("what is Telma for?", "when does Amma take Metformin?"). Without it, Ask still answers what each medicine is for from our medicine information.

## 4. Family
**Account → Family members**: add everyone (Amma, Appa, children) with their relation. In **Notebook**, write each person's allergies.

## 5. Doses and reminders
Times are usually filled in for you (see above). The **Doses** tab lists today's doses; tick each one after taking it, or tap **All taken** to tick a whole morning or night at once. The reminder on your phone has a **✓ Taken** button, so you can tick without opening the app. Tap **Turn on reminders** and allow notifications to get a phone alert at each time, even when the app is closed. On iPhone, add the app to the home screen first.

**Skip a dose.** If a dose is not being taken on purpose, tap **Skip** next to it and choose why. *Side effects* tells the family at once so someone can call the doctor. *Ran out* sets the tablet count to 0, so the medicine goes on the shopping list. *Doctor stopped it* ends the reminders for that medicine. A skipped dose never counts as "missed".

**"Did you take it?"** If a dose isn't ticked, the person whose medicine it is gets a gentle check first (if they use AushadhBox on their own phone), with **✓ Taken** and **Skip** buttons. Only if there is still no answer does the family get the missed-dose alert.

**Last 7 days.** Under the doses, each person's week shows doses taken, skipped and not confirmed, the time of day they miss most, and why doses were skipped. **Copy for doctor** copies it as text. A summary also arrives every Sunday morning, and the printable doctor report shows the last 30 days.

## 6. Automations
Open **Account → Automations** to switch each one on or off.
- **Tablet count:** when adding a medicine, enter *Tablets left* and *Per dose*. Each time you tick a dose, the count goes down by itself (un-ticking puts it back). The drawer shows how many days it will last.
- **Refill alert:** a few days before a medicine runs out (you choose 3, 5, 7 or 10 days), you get a "Buy more" alert. After buying, edit the medicine and update *Tablets left*.
- **Missed-dose alert:** if a dose is not ticked 30–120 minutes after its time, everyone sharing the family cabinet gets an alert. Useful for parents living alone.
- **Courses:** for antibiotics and other short courses, set *Course ends*. Reminders stop by themselves after that day and you get a "Course finished" note.
- **Monthly clean-up:** on the 1st of every month, a list of expired medicines to take back to a pharmacy.
- **Reminder times learn your routine:** if you keep taking your morning medicines at about 9:40 although the reminder says 8:00, after a few days the reminder moves to 9:45 by itself and tells you so. It only follows a steady habit.
- **Shopping list:** the 🛒 button on the cabinet lists everything running low, finished or expired while still in use, with the cheaper Jan Aushadhi generic. Send it to the family or the pharmacy on WhatsApp in one tap. A short course with enough tablets to finish is not listed.
- **Phone calendar:** *Add to Google Calendar* or *Add to iPhone Calendar* puts every dose time in your calendar with an alarm, and it updates itself. If you ever share the link by mistake, tap *Make a new private link*.

## 6a. Smart pillbox (optional hardware)
A pill organiser with a magnet on each lid and a sensor under it (build guide: `hardware/README.md`, about ₹900 of parts).
1. **Account → Smart pillbox:** enter a name, choose whose medicines are in it and how many lids it has, then tap **Add a pillbox**. A 6-digit code appears.
2. Switch on the box, join its Wi-Fi **AushadhBox-Setup** from your phone, pick your home Wi-Fi and type the code.
3. At each dose time the right lid lights up and beeps. Opening it ticks the dose in the app as **by pillbox**. If it is opened again later, the family is asked to check a second dose wasn't taken. Openings while you fill the box on Sunday aren't counted.
4. Without the hardware, use **Try without hardware: open a lid** to see exactly what would happen.
If the box goes offline for a day or its battery runs low, you are told. If a guess is wrong, untick the dose.

## 7. Notebook for the doctor
**Notebook** shows each person's medicines, times and expiry. Tap **Copy for doctor** and paste it into WhatsApp, or show the screen. Premium families can print a full report: **Account → Printable report for doctor → Print or save as PDF**.

## 7a. Health diary, doctor visits and emergency card
Open **Notebook** and choose the person at the top.
- **Health diary:** choose what you measured (blood pressure, sugar, weight, temperature, oxygen, pulse), type the number and tap **Add reading**. The app says whether it is in the normal range. If a reading needs attention now, it says to call the doctor and tells the family. The chart shows how it changes over time.
- **How is it going?** On day 3 and day 10 of a new medicine, the Doses screen asks how it is going: better, same, worse or side effect. A problem is passed to the family and kept for the doctor.
- **Doctor visits:** add the doctor, place, date and time and your questions. You get a reminder the evening before and 2 hours before. **Visit sheet** puts everything the doctor needs on one page: medicines and tablets left, doses taken, side effects, readings and your questions. Print it or share it.
- **Emergency details:** add the blood group, health conditions and an emergency contact. **Open emergency card** shows everything a doctor or ambulance needs, with a QR code. It works without internet, opens from the app icon's shortcut (press and hold the icon), and **Save as image** makes a picture for your lock screen.
- **My pharmacy:** save your pharmacy's WhatsApp number. The shopping list then has **Send to my pharmacy**.
- **Watch-over (Account → Automations):** if a parent with their own phone or a pillbox shows no sign for 12, 24 or 48 hours (no tick, no lid opening, app not opened), the family is told.
- **Print weekly calendar** (Doses) prints a week of tick boxes to keep next to the medicines.

## 8. Voice assistant
Tap **Ask** and speak, e.g. "What is expiring?" or "Which medicines tonight?", in your language. You can also type.

## 9. Family Premium
**Account → Upgrade to Premium.** Choose 1 year (cheaper) or 1 month and pay with UPI, card or net banking through Razorpay. Premium gives:
- **Family sharing:** Account → Family sharing → **Create invite code**. Send the 8-letter code (or link) to your family. They create their own account and enter the code under *Have an invite code?*. Everyone now sees the same cabinet. The owner can remove people; members can leave.
- Unlimited medicines and up to 15 people.
- **Printable report** for doctors.
- **Weekly e-mail** every Monday listing medicines that expired or expire soon (switch off in Account → Profile).

Premium does **not** renew automatically. You get an e-mail 3 days before it ends. If you do not renew, you go back to the Free plan and nothing is deleted.

Refunds: write within 7 days of paying for a full refund (see /refund-policy).

## 10. Your data and privacy
- **Account → Download my data** gives a file with everything stored.
- **Account → Delete my account** removes it all permanently.
- Photos you scan stay on your phone; only the text read from the label is sent.
- Cookie choices: **Cookie settings** at the bottom of any page.

## 11. Problems?
| Problem | Try this |
|---|---|
| Camera does not open | Allow camera access for the site in your browser settings. |
| Scan reads nothing | More light, hold steady, tilt to avoid shine, or type instead. |
| No notifications | Account/Doses → turn on reminders; check the phone's notification settings for the browser. |
| Forgot password | Sign in screen → *Forgot password?* → check your e-mail (and spam). |
| Paid but still Free | Close and reopen the app. Still Free after 10 minutes? Use the Contact page with your Razorpay payment ID. |

> AushadhBox organises medicines. It does not give medical advice. In an emergency call 112. Report side effects to the PvPI helpline 1800-180-3024.
