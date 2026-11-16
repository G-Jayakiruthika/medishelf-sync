/* Text for smart adding (prescription / bill scan, quick add, usual schedules), shopping list and routine learning. Merged into window.I18N. */
(function () {
  const add = {
    en: {
      scanAny: "Works with a medicine strip, a doctor's prescription or a pharmacy bill. One photo adds everything.",
      rxTitlePres: "Medicines on the prescription", rxTitleBill: "Medicines on the bill", rxCheck: "Check the list and save. Untick anything that isn't a medicine.",
      rxFor: "For", rxSave: "Save {n} medicines", rxNone: "No medicines found. Try a clearer, flat photo in good light, or type them below.",
      rxPaste: "Paste prescription text", rxPasteHint: "From an e-prescription, SMS or WhatsApp message", rxRead: "Read it", rxReading: "Reading the prescription…",
      days: "Days", expShort: "Expiry", stockShort: "Tablets", usualTag: "usual",
      usualFilled: "Usual schedule filled in. Follow your doctor's prescription if it is different.", clear: "Clear",
      quickTitle: "Add by typing or speaking", quickPh: "e.g. Dolo 650 twice a day for 3 days for Amma", quickAdd: "Add", quickDone: "Added {name}. Scan the strip later to add its expiry.", quickNotUnderstood: "Say the medicine name first, then how often. For example: Metformin 500 morning and night.",
      expUnknown: "Expiry not added", addExpiry: "Scan the strip to add the expiry",
      shopTitle: "Shopping list", shopEmpty: "Nothing to buy right now.", shopLow: "running low", shopOut: "finished", shopExpired: "expired, buy a fresh pack", shopCopy: "Copy list", shopShare: "Share on WhatsApp", shopGeneric: "Cheaper generic: {g}",
      autoTiming: "Move reminder times to when we really take the medicines", takenAll: "All taken"
    },
    hi: {
      scanAny: "दवा का पत्ता, डॉक्टर का पर्चा या केमिस्ट का बिल, किसी की भी फोटो लें। एक फोटो से सब जुड़ जाता है।",
      rxTitlePres: "पर्चे पर लिखी दवाएँ", rxTitleBill: "बिल पर लिखी दवाएँ", rxCheck: "सूची जाँचें और सेव करें। जो दवा नहीं है उसका टिक हटा दें।",
      rxFor: "किसके लिए", rxSave: "{n} दवाएँ सेव करें", rxNone: "कोई दवा नहीं मिली। साफ़, सीधी और अच्छी रोशनी वाली फोटो लें, या नीचे लिखें।",
      rxPaste: "पर्चे का टेक्स्ट पेस्ट करें", rxPasteHint: "ई-प्रिस्क्रिप्शन, SMS या WhatsApp संदेश से", rxRead: "पढ़ें", rxReading: "पर्चा पढ़ा जा रहा है…",
      days: "दिन", expShort: "एक्सपायरी", stockShort: "गोलियाँ", usualTag: "सामान्य",
      usualFilled: "सामान्य समय भर दिया गया है। डॉक्टर ने अलग बताया हो तो वही मानें।", clear: "हटाएँ",
      quickTitle: "लिखकर या बोलकर जोड़ें", quickPh: "जैसे: Dolo 650 दिन में दो बार 3 दिन अम्मा के लिए", quickAdd: "जोड़ें", quickDone: "{name} जुड़ गई। एक्सपायरी के लिए बाद में पत्ता स्कैन करें।", quickNotUnderstood: "पहले दवा का नाम, फिर कितनी बार बोलें। जैसे: Metformin 500 सुबह और रात।",
      expUnknown: "एक्सपायरी नहीं जोड़ी", addExpiry: "एक्सपायरी जोड़ने के लिए पत्ता स्कैन करें",
      shopTitle: "खरीदारी सूची", shopEmpty: "अभी कुछ खरीदना नहीं है।", shopLow: "खत्म होने वाली", shopOut: "खत्म", shopExpired: "एक्सपायर, नया पैक लें", shopCopy: "सूची कॉपी करें", shopShare: "WhatsApp पर भेजें", shopGeneric: "सस्ती जेनेरिक: {g}",
      autoTiming: "रिमाइंडर का समय उस समय पर ले जाएँ जब हम सच में दवा लेते हैं", takenAll: "सब ले ली"
    },
    bn: {
      scanAny: "ওষুধের স্ট্রিপ, ডাক্তারের প্রেসক্রিপশন বা দোকানের বিল, যেকোনো একটির ছবি তুলুন। এক ছবিতেই সব যোগ হয়।",
      rxTitlePres: "প্রেসক্রিপশনের ওষুধ", rxTitleBill: "বিলের ওষুধ", rxCheck: "তালিকা দেখে সেভ করুন। যা ওষুধ নয় তার টিক তুলে দিন।",
      rxFor: "কার জন্য", rxSave: "{n}টি ওষুধ সেভ করুন", rxNone: "কোনো ওষুধ পাওয়া যায়নি। পরিষ্কার, সোজা ছবি তুলুন, বা নিচে লিখুন।",
      rxPaste: "প্রেসক্রিপশনের লেখা পেস্ট করুন", rxPasteHint: "ই-প্রেসক্রিপশন, SMS বা WhatsApp থেকে", rxRead: "পড়ুন", rxReading: "প্রেসক্রিপশন পড়া হচ্ছে…",
      days: "দিন", expShort: "মেয়াদ", stockShort: "ট্যাবলেট", usualTag: "সাধারণ",
      usualFilled: "সাধারণ সময় বসানো হয়েছে। ডাক্তার অন্যরকম বললে সেটাই মানুন।", clear: "মুছুন",
      quickTitle: "লিখে বা বলে যোগ করুন", quickPh: "যেমন: Dolo 650 দিনে দুবার 3 দিন মায়ের জন্য", quickAdd: "যোগ করুন", quickDone: "{name} যোগ হয়েছে। পরে স্ট্রিপ স্ক্যান করে মেয়াদ যোগ করুন।", quickNotUnderstood: "আগে ওষুধের নাম, তারপর কতবার বলুন। যেমন: Metformin 500 সকালে আর রাতে।",
      expUnknown: "মেয়াদ যোগ হয়নি", addExpiry: "মেয়াদ যোগ করতে স্ট্রিপ স্ক্যান করুন",
      shopTitle: "কেনাকাটার তালিকা", shopEmpty: "এখন কিছু কিনতে হবে না।", shopLow: "ফুরিয়ে আসছে", shopOut: "শেষ", shopExpired: "মেয়াদ শেষ, নতুন প্যাক কিনুন", shopCopy: "তালিকা কপি করুন", shopShare: "WhatsApp-এ পাঠান", shopGeneric: "সস্তা জেনেরিক: {g}",
      autoTiming: "আমরা যখন আসলে ওষুধ খাই, রিমাইন্ডার সেই সময়ে সরান", takenAll: "সব খাওয়া হয়েছে"
    },
    mr: {
      scanAny: "औषधाची पट्टी, डॉक्टरांची चिठ्ठी किंवा केमिस्टचे बिल, कशाचाही फोटो घ्या. एका फोटोत सर्व जोडले जाते.",
      rxTitlePres: "चिठ्ठीवरील औषधे", rxTitleBill: "बिलावरील औषधे", rxCheck: "यादी तपासा आणि सेव्ह करा. जे औषध नाही त्याचा टिक काढा.",
      rxFor: "कोणासाठी", rxSave: "{n} औषधे सेव्ह करा", rxNone: "औषधे सापडली नाहीत. स्पष्ट, सरळ फोटो घ्या किंवा खाली लिहा.",
      rxPaste: "चिठ्ठीचा मजकूर पेस्ट करा", rxPasteHint: "ई-प्रिस्क्रिप्शन, SMS किंवा WhatsApp मधून", rxRead: "वाचा", rxReading: "चिठ्ठी वाचत आहे…",
      days: "दिवस", expShort: "मुदत", stockShort: "गोळ्या", usualTag: "नेहमीचे",
      usualFilled: "नेहमीची वेळ भरली आहे. डॉक्टरांनी वेगळे सांगितले असल्यास तेच पाळा.", clear: "काढा",
      quickTitle: "लिहून किंवा बोलून जोडा", quickPh: "उदा. Dolo 650 दिवसातून दोनदा 3 दिवस आईसाठी", quickAdd: "जोडा", quickDone: "{name} जोडले. मुदतीसाठी नंतर पट्टी स्कॅन करा.", quickNotUnderstood: "आधी औषधाचे नाव, मग किती वेळा सांगा. उदा. Metformin 500 सकाळी आणि रात्री.",
      expUnknown: "मुदत जोडलेली नाही", addExpiry: "मुदत जोडण्यासाठी पट्टी स्कॅन करा",
      shopTitle: "खरेदी यादी", shopEmpty: "आत्ता काही विकत घ्यायचे नाही.", shopLow: "संपत आले", shopOut: "संपले", shopExpired: "मुदत संपली, नवा पॅक घ्या", shopCopy: "यादी कॉपी करा", shopShare: "WhatsApp वर पाठवा", shopGeneric: "स्वस्त जेनेरिक: {g}",
      autoTiming: "आपण खरंच औषध घेतो त्या वेळेनुसार रिमाइंडर हलवा", takenAll: "सर्व घेतली"
    },
    te: {
      scanAny: "మందుల స్ట్రిప్, డాక్టర్ ప్రిస్క్రిప్షన్ లేదా ఫార్మసీ బిల్లు, ఏదైనా ఫోటో తీయండి. ఒక్క ఫోటోతో అన్నీ జోడించబడతాయి.",
      rxTitlePres: "ప్రిస్క్రిప్షన్‌లోని మందులు", rxTitleBill: "బిల్లులోని మందులు", rxCheck: "జాబితా చూసి సేవ్ చేయండి. మందు కానిదానికి టిక్ తీసేయండి.",
      rxFor: "ఎవరికి", rxSave: "{n} మందులు సేవ్ చేయండి", rxNone: "మందులు దొరకలేదు. స్పష్టమైన ఫోటో తీయండి, లేదా కింద టైప్ చేయండి.",
      rxPaste: "ప్రిస్క్రిప్షన్ టెక్స్ట్ పేస్ట్ చేయండి", rxPasteHint: "ఇ-ప్రిస్క్రిప్షన్, SMS లేదా WhatsApp నుండి", rxRead: "చదవండి", rxReading: "ప్రిస్క్రిప్షన్ చదువుతోంది…",
      days: "రోజులు", expShort: "గడువు", stockShort: "మాత్రలు", usualTag: "సాధారణ",
      usualFilled: "సాధారణ సమయం నింపబడింది. డాక్టర్ వేరేగా చెప్పి ఉంటే అదే పాటించండి.", clear: "తీసివేయండి",
      quickTitle: "టైప్ చేసి లేదా మాట్లాడి జోడించండి", quickPh: "ఉదా: Dolo 650 రోజుకు రెండుసార్లు 3 రోజులు అమ్మకు", quickAdd: "జోడించండి", quickDone: "{name} జోడించబడింది. గడువు కోసం తర్వాత స్ట్రిప్ స్కాన్ చేయండి.", quickNotUnderstood: "ముందు మందు పేరు, తర్వాత ఎన్నిసార్లు చెప్పండి. ఉదా: Metformin 500 ఉదయం రాత్రి.",
      expUnknown: "గడువు జోడించలేదు", addExpiry: "గడువు జోడించడానికి స్ట్రిప్ స్కాన్ చేయండి",
      shopTitle: "కొనుగోలు జాబితా", shopEmpty: "ఇప్పుడు ఏమీ కొనాల్సిన అవసరం లేదు.", shopLow: "అయిపోవస్తోంది", shopOut: "అయిపోయింది", shopExpired: "గడువు ముగిసింది, కొత్త ప్యాక్ కొనండి", shopCopy: "జాబితా కాపీ చేయండి", shopShare: "WhatsApp‌లో పంపండి", shopGeneric: "చౌక జనరిక్: {g}",
      autoTiming: "మేము నిజంగా మందు వేసుకునే సమయానికి రిమైండర్ మార్చండి", takenAll: "అన్నీ వేసుకున్నాం"
    },
    ta: {
      scanAny: "மாத்திரை அட்டை, மருத்துவர் சீட்டு அல்லது மருந்தக பில், எதையும் படம் எடுங்கள். ஒரே படத்தில் எல்லாம் சேரும்.",
      rxTitlePres: "சீட்டில் உள்ள மருந்துகள்", rxTitleBill: "பில்லில் உள்ள மருந்துகள்", rxCheck: "பட்டியலைச் சரிபார்த்து சேமியுங்கள். மருந்து இல்லாததின் டிக்கை நீக்குங்கள்.",
      rxFor: "யாருக்கு", rxSave: "{n} மருந்துகளைச் சேமி", rxNone: "மருந்துகள் கிடைக்கவில்லை. தெளிவான, நேரான படம் எடுங்கள், அல்லது கீழே தட்டச்சு செய்யுங்கள்.",
      rxPaste: "சீட்டு உரையை ஒட்டு", rxPasteHint: "இ-சீட்டு, SMS அல்லது WhatsApp செய்தியிலிருந்து", rxRead: "படி", rxReading: "சீட்டைப் படிக்கிறது…",
      days: "நாட்கள்", expShort: "காலாவதி", stockShort: "மாத்திரைகள்", usualTag: "வழக்கம்",
      usualFilled: "வழக்கமான நேரம் நிரப்பப்பட்டது. மருத்துவர் வேறு சொன்னால் அதையே பின்பற்றுங்கள்.", clear: "நீக்கு",
      quickTitle: "தட்டச்சு செய்தோ பேசியோ சேர்", quickPh: "எ.கா. Dolo 650 தினமும் இருமுறை 3 நாட்கள் அம்மாவுக்கு", quickAdd: "சேர்", quickDone: "{name} சேர்க்கப்பட்டது. காலாவதிக்கு பிறகு அட்டையை ஸ்கேன் செய்யுங்கள்.", quickNotUnderstood: "முதலில் மருந்தின் பெயர், பிறகு எத்தனை முறை என்று சொல்லுங்கள். எ.கா. Metformin 500 காலை இரவு.",
      expUnknown: "காலாவதி சேர்க்கப்படவில்லை", addExpiry: "காலாவதி சேர்க்க அட்டையை ஸ்கேன் செய்",
      shopTitle: "வாங்கும் பட்டியல்", shopEmpty: "இப்போது எதுவும் வாங்க வேண்டியதில்லை.", shopLow: "தீர்ந்து வருகிறது", shopOut: "தீர்ந்தது", shopExpired: "காலாவதி, புதிய அட்டை வாங்குங்கள்", shopCopy: "பட்டியலை நகலெடு", shopShare: "WhatsApp-இல் அனுப்பு", shopGeneric: "மலிவான ஜெனரிக்: {g}",
      autoTiming: "நாங்கள் உண்மையில் மருந்து எடுக்கும் நேரத்துக்கு நினைவூட்டலை மாற்று", takenAll: "எல்லாம் எடுத்தாச்சு"
    },
    gu: {
      scanAny: "દવાની પટ્ટી, ડૉક્ટરનું પ્રિસ્ક્રિપ્શન કે કેમિસ્ટનું બિલ, કોઈનો પણ ફોટો લો. એક ફોટોથી બધું ઉમેરાઈ જાય છે.",
      rxTitlePres: "પ્રિસ્ક્રિપ્શનની દવાઓ", rxTitleBill: "બિલની દવાઓ", rxCheck: "યાદી તપાસી સેવ કરો. જે દવા નથી તેનો ટિક કાઢો.",
      rxFor: "કોના માટે", rxSave: "{n} દવાઓ સેવ કરો", rxNone: "દવાઓ ન મળી. સ્પષ્ટ, સીધો ફોટો લો, અથવા નીચે લખો.",
      rxPaste: "પ્રિસ્ક્રિપ્શનનું લખાણ પેસ્ટ કરો", rxPasteHint: "ઈ-પ્રિસ્ક્રિપ્શન, SMS કે WhatsApp માંથી", rxRead: "વાંચો", rxReading: "પ્રિસ્ક્રિપ્શન વંચાય છે…",
      days: "દિવસ", expShort: "એક્સપાયરી", stockShort: "ગોળીઓ", usualTag: "સામાન્ય",
      usualFilled: "સામાન્ય સમય ભર્યો છે. ડૉક્ટરે જુદું કહ્યું હોય તો તે જ માનો.", clear: "કાઢો",
      quickTitle: "લખીને કે બોલીને ઉમેરો", quickPh: "દા.ત. Dolo 650 દિવસમાં બે વાર 3 દિવસ બા માટે", quickAdd: "ઉમેરો", quickDone: "{name} ઉમેરાઈ. એક્સપાયરી માટે પછી પટ્ટી સ્કેન કરો.", quickNotUnderstood: "પહેલાં દવાનું નામ, પછી કેટલી વાર બોલો. દા.ત. Metformin 500 સવારે અને રાત્રે.",
      expUnknown: "એક્સપાયરી ઉમેરી નથી", addExpiry: "એક્સપાયરી ઉમેરવા પટ્ટી સ્કેન કરો",
      shopTitle: "ખરીદીની યાદી", shopEmpty: "હમણાં કંઈ ખરીદવાનું નથી.", shopLow: "ખૂટવા આવી", shopOut: "ખતમ", shopExpired: "એક્સપાયર, નવું પૅક લો", shopCopy: "યાદી કૉપિ કરો", shopShare: "WhatsApp પર મોકલો", shopGeneric: "સસ્તી જેનેરિક: {g}",
      autoTiming: "અમે ખરેખર દવા લઈએ તે સમયે રિમાઇન્ડર ખસેડો", takenAll: "બધી લીધી"
    },
    kn: {
      scanAny: "ಔಷಧದ ಸ್ಟ್ರಿಪ್, ವೈದ್ಯರ ಚೀಟಿ ಅಥವಾ ಫಾರ್ಮಸಿ ಬಿಲ್, ಯಾವುದರದ್ದಾದರೂ ಫೋಟೋ ತೆಗೆಯಿರಿ. ಒಂದೇ ಫೋಟೋದಲ್ಲಿ ಎಲ್ಲವೂ ಸೇರುತ್ತದೆ.",
      rxTitlePres: "ಚೀಟಿಯಲ್ಲಿನ ಔಷಧಗಳು", rxTitleBill: "ಬಿಲ್‌ನಲ್ಲಿನ ಔಷಧಗಳು", rxCheck: "ಪಟ್ಟಿ ಪರಿಶೀಲಿಸಿ ಉಳಿಸಿ. ಔಷಧವಲ್ಲದ್ದರ ಟಿಕ್ ತೆಗೆಯಿರಿ.",
      rxFor: "ಯಾರಿಗೆ", rxSave: "{n} ಔಷಧಗಳನ್ನು ಉಳಿಸಿ", rxNone: "ಔಷಧಗಳು ಸಿಗಲಿಲ್ಲ. ಸ್ಪಷ್ಟ, ನೇರ ಫೋಟೋ ತೆಗೆಯಿರಿ, ಅಥವಾ ಕೆಳಗೆ ಟೈಪ್ ಮಾಡಿ.",
      rxPaste: "ಚೀಟಿಯ ಪಠ್ಯ ಅಂಟಿಸಿ", rxPasteHint: "ಇ-ಚೀಟಿ, SMS ಅಥವಾ WhatsApp ನಿಂದ", rxRead: "ಓದಿ", rxReading: "ಚೀಟಿ ಓದಲಾಗುತ್ತಿದೆ…",
      days: "ದಿನಗಳು", expShort: "ಅವಧಿ", stockShort: "ಮಾತ್ರೆಗಳು", usualTag: "ಸಾಮಾನ್ಯ",
      usualFilled: "ಸಾಮಾನ್ಯ ಸಮಯ ತುಂಬಲಾಗಿದೆ. ವೈದ್ಯರು ಬೇರೆ ಹೇಳಿದ್ದರೆ ಅದನ್ನೇ ಪಾಲಿಸಿ.", clear: "ತೆಗೆ",
      quickTitle: "ಟೈಪ್ ಮಾಡಿ ಅಥವಾ ಮಾತನಾಡಿ ಸೇರಿಸಿ", quickPh: "ಉದಾ: Dolo 650 ದಿನಕ್ಕೆ ಎರಡು ಬಾರಿ 3 ದಿನ ಅಮ್ಮನಿಗೆ", quickAdd: "ಸೇರಿಸಿ", quickDone: "{name} ಸೇರಿಸಲಾಗಿದೆ. ಅವಧಿಗಾಗಿ ನಂತರ ಸ್ಟ್ರಿಪ್ ಸ್ಕ್ಯಾನ್ ಮಾಡಿ.", quickNotUnderstood: "ಮೊದಲು ಔಷಧದ ಹೆಸರು, ನಂತರ ಎಷ್ಟು ಬಾರಿ ಹೇಳಿ. ಉದಾ: Metformin 500 ಬೆಳಿಗ್ಗೆ ರಾತ್ರಿ.",
      expUnknown: "ಅವಧಿ ಸೇರಿಸಿಲ್ಲ", addExpiry: "ಅವಧಿ ಸೇರಿಸಲು ಸ್ಟ್ರಿಪ್ ಸ್ಕ್ಯಾನ್ ಮಾಡಿ",
      shopTitle: "ಖರೀದಿ ಪಟ್ಟಿ", shopEmpty: "ಈಗ ಏನೂ ಖರೀದಿಸಬೇಕಿಲ್ಲ.", shopLow: "ಮುಗಿಯುತ್ತಿದೆ", shopOut: "ಮುಗಿದಿದೆ", shopExpired: "ಅವಧಿ ಮುಗಿದಿದೆ, ಹೊಸ ಪ್ಯಾಕ್ ತನ್ನಿ", shopCopy: "ಪಟ್ಟಿ ನಕಲಿಸಿ", shopShare: "WhatsApp‌ನಲ್ಲಿ ಕಳುಹಿಸಿ", shopGeneric: "ಅಗ್ಗದ ಜೆನೆರಿಕ್: {g}",
      autoTiming: "ನಾವು ನಿಜವಾಗಿ ಔಷಧ ತೆಗೆದುಕೊಳ್ಳುವ ಸಮಯಕ್ಕೆ ಜ್ಞಾಪನೆ ಬದಲಿಸಿ", takenAll: "ಎಲ್ಲಾ ತೆಗೆದುಕೊಂಡೆ"
    },
    ml: {
      scanAny: "മരുന്ന് സ്ട്രിപ്പ്, ഡോക്ടറുടെ കുറിപ്പടി, ഫാർമസി ബിൽ, ഏതിന്റെയും ഫോട്ടോ എടുക്കൂ. ഒരു ഫോട്ടോയിൽ എല്ലാം ചേരും.",
      rxTitlePres: "കുറിപ്പടിയിലെ മരുന്നുകൾ", rxTitleBill: "ബില്ലിലെ മരുന്നുകൾ", rxCheck: "പട്ടിക പരിശോധിച്ച് സേവ് ചെയ്യൂ. മരുന്നല്ലാത്തതിന്റെ ടിക്ക് മാറ്റൂ.",
      rxFor: "ആർക്ക്", rxSave: "{n} മരുന്നുകൾ സേവ് ചെയ്യൂ", rxNone: "മരുന്നുകൾ കണ്ടെത്തിയില്ല. വ്യക്തമായ, നേരായ ഫോട്ടോ എടുക്കൂ, അല്ലെങ്കിൽ താഴെ ടൈപ്പ് ചെയ്യൂ.",
      rxPaste: "കുറിപ്പടിയുടെ ടെക്സ്റ്റ് ഒട്ടിക്കൂ", rxPasteHint: "ഇ-കുറിപ്പടി, SMS, WhatsApp എന്നിവയിൽ നിന്ന്", rxRead: "വായിക്കൂ", rxReading: "കുറിപ്പടി വായിക്കുന്നു…",
      days: "ദിവസം", expShort: "കാലാവധി", stockShort: "ഗുളികകൾ", usualTag: "സാധാരണ",
      usualFilled: "സാധാരണ സമയം നിറച്ചു. ഡോക്ടർ വേറെ പറഞ്ഞിട്ടുണ്ടെങ്കിൽ അത് പാലിക്കൂ.", clear: "മാറ്റൂ",
      quickTitle: "ടൈപ്പ് ചെയ്തോ പറഞ്ഞോ ചേർക്കൂ", quickPh: "ഉദാ: Dolo 650 ദിവസം രണ്ടുതവണ 3 ദിവസം അമ്മയ്ക്ക്", quickAdd: "ചേർക്കൂ", quickDone: "{name} ചേർത്തു. കാലാവധിക്കായി പിന്നീട് സ്ട്രിപ്പ് സ്കാൻ ചെയ്യൂ.", quickNotUnderstood: "ആദ്യം മരുന്നിന്റെ പേര്, പിന്നെ എത്ര തവണ എന്ന് പറയൂ. ഉദാ: Metformin 500 രാവിലെ രാത്രി.",
      expUnknown: "കാലാവധി ചേർത്തിട്ടില്ല", addExpiry: "കാലാവധി ചേർക്കാൻ സ്ട്രിപ്പ് സ്കാൻ ചെയ്യൂ",
      shopTitle: "വാങ്ങൽ പട്ടിക", shopEmpty: "ഇപ്പോൾ ഒന്നും വാങ്ങേണ്ടതില്ല.", shopLow: "തീരാറായി", shopOut: "തീർന്നു", shopExpired: "കാലാവധി കഴിഞ്ഞു, പുതിയ പായ്ക്ക് വാങ്ങൂ", shopCopy: "പട്ടിക പകർത്തൂ", shopShare: "WhatsApp-ൽ അയയ്ക്കൂ", shopGeneric: "വിലകുറഞ്ഞ ജനറിക്: {g}",
      autoTiming: "ഞങ്ങൾ യഥാർത്ഥത്തിൽ മരുന്ന് കഴിക്കുന്ന സമയത്തേക്ക് ഓർമ്മപ്പെടുത്തൽ മാറ്റൂ", takenAll: "എല്ലാം കഴിച്ചു"
    },
    pa: {
      scanAny: "ਦਵਾਈ ਦਾ ਪੱਤਾ, ਡਾਕਟਰ ਦੀ ਪਰਚੀ ਜਾਂ ਕੈਮਿਸਟ ਦਾ ਬਿੱਲ, ਕਿਸੇ ਦੀ ਵੀ ਫੋਟੋ ਲਓ। ਇੱਕ ਫੋਟੋ ਨਾਲ ਸਭ ਜੁੜ ਜਾਂਦਾ ਹੈ।",
      rxTitlePres: "ਪਰਚੀ ਦੀਆਂ ਦਵਾਈਆਂ", rxTitleBill: "ਬਿੱਲ ਦੀਆਂ ਦਵਾਈਆਂ", rxCheck: "ਸੂਚੀ ਜਾਂਚ ਕੇ ਸੇਵ ਕਰੋ। ਜੋ ਦਵਾਈ ਨਹੀਂ ਉਸਦਾ ਟਿਕ ਹਟਾਓ।",
      rxFor: "ਕਿਸ ਲਈ", rxSave: "{n} ਦਵਾਈਆਂ ਸੇਵ ਕਰੋ", rxNone: "ਦਵਾਈਆਂ ਨਹੀਂ ਮਿਲੀਆਂ। ਸਾਫ਼, ਸਿੱਧੀ ਫੋਟੋ ਲਓ, ਜਾਂ ਹੇਠਾਂ ਲਿਖੋ।",
      rxPaste: "ਪਰਚੀ ਦਾ ਟੈਕਸਟ ਪੇਸਟ ਕਰੋ", rxPasteHint: "ਈ-ਪਰਚੀ, SMS ਜਾਂ WhatsApp ਤੋਂ", rxRead: "ਪੜ੍ਹੋ", rxReading: "ਪਰਚੀ ਪੜ੍ਹੀ ਜਾ ਰਹੀ ਹੈ…",
      days: "ਦਿਨ", expShort: "ਮਿਆਦ", stockShort: "ਗੋਲੀਆਂ", usualTag: "ਆਮ",
      usualFilled: "ਆਮ ਸਮਾਂ ਭਰ ਦਿੱਤਾ ਹੈ। ਡਾਕਟਰ ਨੇ ਵੱਖਰਾ ਕਿਹਾ ਹੋਵੇ ਤਾਂ ਉਹੀ ਮੰਨੋ।", clear: "ਹਟਾਓ",
      quickTitle: "ਲਿਖ ਕੇ ਜਾਂ ਬੋਲ ਕੇ ਜੋੜੋ", quickPh: "ਜਿਵੇਂ: Dolo 650 ਦਿਨ ਵਿੱਚ ਦੋ ਵਾਰ 3 ਦਿਨ ਮਾਂ ਲਈ", quickAdd: "ਜੋੜੋ", quickDone: "{name} ਜੁੜ ਗਈ। ਮਿਆਦ ਲਈ ਬਾਅਦ ਵਿੱਚ ਪੱਤਾ ਸਕੈਨ ਕਰੋ।", quickNotUnderstood: "ਪਹਿਲਾਂ ਦਵਾਈ ਦਾ ਨਾਂ, ਫਿਰ ਕਿੰਨੀ ਵਾਰ ਬੋਲੋ। ਜਿਵੇਂ: Metformin 500 ਸਵੇਰੇ ਅਤੇ ਰਾਤ।",
      expUnknown: "ਮਿਆਦ ਨਹੀਂ ਜੋੜੀ", addExpiry: "ਮਿਆਦ ਜੋੜਨ ਲਈ ਪੱਤਾ ਸਕੈਨ ਕਰੋ",
      shopTitle: "ਖਰੀਦ ਸੂਚੀ", shopEmpty: "ਹੁਣ ਕੁਝ ਖਰੀਦਣ ਦੀ ਲੋੜ ਨਹੀਂ।", shopLow: "ਮੁੱਕਣ ਵਾਲੀ", shopOut: "ਮੁੱਕ ਗਈ", shopExpired: "ਮਿਆਦ ਪੁੱਗੀ, ਨਵਾਂ ਪੈਕ ਲਓ", shopCopy: "ਸੂਚੀ ਕਾਪੀ ਕਰੋ", shopShare: "WhatsApp 'ਤੇ ਭੇਜੋ", shopGeneric: "ਸਸਤੀ ਜੈਨਰਿਕ: {g}",
      autoTiming: "ਅਸੀਂ ਅਸਲ ਵਿੱਚ ਦਵਾਈ ਲੈਂਦੇ ਹਾਂ ਉਸ ਸਮੇਂ 'ਤੇ ਯਾਦ-ਦਹਾਨੀ ਲੈ ਜਾਓ", takenAll: "ਸਭ ਲੈ ਲਈਆਂ"
    }
  };
  for (const k in add) window.I18N[k] = Object.assign(window.I18N[k] || {}, add[k]);
})();
