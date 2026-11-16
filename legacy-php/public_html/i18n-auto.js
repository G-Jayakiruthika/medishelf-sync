/* Text for automations (stock count, refill, missed doses, courses, clean-up, calendar), merged into window.I18N. */
(function () {
  const add = {
    en: {
      fStock: "Tablets left", fPerDose: "Per dose", fCourseEnd: "Course ends (optional)", fStockHint: "Counts down by itself each time you tick a dose.",
      stockLeft: "{n} left, {d} days", stockLeftOnly: "{n} left", courseEnds: "Course ends {d}", courseDone: "Course finished",
      autoTitle: "Automations", autoDesc: "Things AushadhBox does for you by itself. Alerts arrive as phone notifications, or by e-mail if notifications are off.",
      autoRefill: "Refill alert before medicine runs out", autoMissed: "Tell the family when a dose is not ticked", autoCourse: "Stop reminders when a course ends",
      autoCleanup: "Monthly list of expired medicines to throw away", daysBefore: "{d} days before", minutesAfter: "{m} min after the dose time",
      calTitle: "Doses in your phone calendar", calDesc: "Every dose time with an alarm. It updates by itself when medicines change, and works even if app notifications don't.",
      calGoogle: "Add to Google Calendar", calApple: "Add to iPhone Calendar", calCopy: "Copy link", calRenew: "Make a new private link", calCopied: "Link copied"
    },
    hi: {
      fStock: "बची गोलियाँ", fPerDose: "हर खुराक में", fCourseEnd: "कोर्स खत्म (वैकल्पिक)", fStockHint: "हर खुराक पर टिक करने पर अपने-आप घटती है।",
      stockLeft: "{n} बचीं, {d} दिन", stockLeftOnly: "{n} बचीं", courseEnds: "कोर्स {d} को खत्म", courseDone: "कोर्स पूरा",
      autoTitle: "ऑटोमेशन", autoDesc: "AushadhBox ये काम अपने-आप करता है। अलर्ट फ़ोन सूचना से आते हैं, सूचना बंद हो तो ई-मेल से।",
      autoRefill: "दवा खत्म होने से पहले दोबारा खरीदने का अलर्ट", autoMissed: "खुराक टिक न होने पर परिवार को बताएँ", autoCourse: "कोर्स खत्म होने पर रिमाइंडर बंद करें",
      autoCleanup: "हर महीने एक्सपायर दवाओं की सूची", daysBefore: "{d} दिन पहले", minutesAfter: "खुराक के समय के {m} मिनट बाद",
      calTitle: "फ़ोन कैलेंडर में खुराकें", calDesc: "हर खुराक का समय अलार्म के साथ। दवाएँ बदलने पर अपने-आप अपडेट होता है।",
      calGoogle: "Google Calendar में जोड़ें", calApple: "iPhone Calendar में जोड़ें", calCopy: "लिंक कॉपी करें", calRenew: "नया निजी लिंक बनाएँ", calCopied: "लिंक कॉपी हुआ"
    },
    bn: {
      fStock: "বাকি ট্যাবলেট", fPerDose: "প্রতি ডোজে", fCourseEnd: "কোর্স শেষ (ঐচ্ছিক)", fStockHint: "প্রতিবার ডোজে টিক দিলে নিজে থেকেই কমে।",
      stockLeft: "{n}টি বাকি, {d} দিন", stockLeftOnly: "{n}টি বাকি", courseEnds: "কোর্স শেষ {d}", courseDone: "কোর্স শেষ হয়েছে",
      autoTitle: "অটোমেশন", autoDesc: "AushadhBox এগুলো নিজে থেকেই করে। অ্যালার্ট ফোনে আসে, নোটিফিকেশন বন্ধ থাকলে ই-মেলে।",
      autoRefill: "ওষুধ ফুরোনোর আগে আবার কেনার অ্যালার্ট", autoMissed: "ডোজে টিক না পড়লে পরিবারকে জানান", autoCourse: "কোর্স শেষ হলে রিমাইন্ডার বন্ধ",
      autoCleanup: "প্রতি মাসে মেয়াদোত্তীর্ণ ওষুধের তালিকা", daysBefore: "{d} দিন আগে", minutesAfter: "ডোজের সময়ের {m} মিনিট পরে",
      calTitle: "ফোনের ক্যালেন্ডারে ডোজ", calDesc: "প্রতিটি ডোজের সময় অ্যালার্ম সহ। ওষুধ বদলালে নিজে থেকেই আপডেট হয়।",
      calGoogle: "Google Calendar-এ যোগ করুন", calApple: "iPhone Calendar-এ যোগ করুন", calCopy: "লিংক কপি করুন", calRenew: "নতুন ব্যক্তিগত লিংক", calCopied: "লিংক কপি হয়েছে"
    },
    mr: {
      fStock: "उरलेल्या गोळ्या", fPerDose: "प्रत्येक डोसला", fCourseEnd: "कोर्स संपतो (ऐच्छिक)", fStockHint: "प्रत्येक डोसवर टिक केल्यावर आपोआप कमी होते.",
      stockLeft: "{n} उरल्या, {d} दिवस", stockLeftOnly: "{n} उरल्या", courseEnds: "कोर्स {d} ला संपतो", courseDone: "कोर्स पूर्ण",
      autoTitle: "ऑटोमेशन", autoDesc: "AushadhBox ही कामे आपोआप करते. अलर्ट फोन सूचनेने येतात, सूचना बंद असल्यास ई-मेलने.",
      autoRefill: "औषध संपण्यापूर्वी पुन्हा घेण्याचा अलर्ट", autoMissed: "डोस टिक न झाल्यास कुटुंबाला कळवा", autoCourse: "कोर्स संपल्यावर रिमाइंडर बंद करा",
      autoCleanup: "दर महिन्याला मुदत संपलेल्या औषधांची यादी", daysBefore: "{d} दिवस आधी", minutesAfter: "डोसच्या वेळेनंतर {m} मिनिटे",
      calTitle: "फोन कॅलेंडरमध्ये डोस", calDesc: "प्रत्येक डोसची वेळ अलार्मसह. औषधे बदलल्यावर आपोआप अपडेट होते.",
      calGoogle: "Google Calendar मध्ये जोडा", calApple: "iPhone Calendar मध्ये जोडा", calCopy: "लिंक कॉपी करा", calRenew: "नवीन खासगी लिंक बनवा", calCopied: "लिंक कॉपी झाली"
    },
    te: {
      fStock: "మిగిలిన మాత్రలు", fPerDose: "ఒక్కో డోసుకు", fCourseEnd: "కోర్సు ముగింపు (ఐచ్ఛికం)", fStockHint: "ప్రతి డోసుకు టిక్ పెట్టినప్పుడు తనంతట తానే తగ్గుతుంది.",
      stockLeft: "{n} మిగిలాయి, {d} రోజులు", stockLeftOnly: "{n} మిగిలాయి", courseEnds: "కోర్సు {d}న ముగుస్తుంది", courseDone: "కోర్సు పూర్తయింది",
      autoTitle: "ఆటోమేషన్", autoDesc: "AushadhBox ఇవి తనంతట తానే చేస్తుంది. అలర్ట్‌లు ఫోన్ నోటిఫికేషన్‌గా, అవి ఆఫ్ అయితే ఇ-మెయిల్‌గా వస్తాయి.",
      autoRefill: "మందు అయిపోకముందే మళ్ళీ కొనమని అలర్ట్", autoMissed: "డోసు టిక్ కాకపోతే కుటుంబానికి తెలియజేయండి", autoCourse: "కోర్సు ముగిసినప్పుడు రిమైండర్లు ఆపండి",
      autoCleanup: "ప్రతి నెలా గడువు ముగిసిన మందుల జాబితా", daysBefore: "{d} రోజుల ముందు", minutesAfter: "డోసు సమయం తర్వాత {m} నిమిషాలు",
      calTitle: "ఫోన్ క్యాలెండర్‌లో డోసులు", calDesc: "ప్రతి డోసు సమయం అలారంతో. మందులు మారితే తనంతట తానే అప్‌డేట్ అవుతుంది.",
      calGoogle: "Google Calendar‌కు జోడించండి", calApple: "iPhone Calendar‌కు జోడించండి", calCopy: "లింక్ కాపీ చేయండి", calRenew: "కొత్త ప్రైవేట్ లింక్", calCopied: "లింక్ కాపీ అయింది"
    },
    ta: {
      fStock: "மீதமுள்ள மாத்திரைகள்", fPerDose: "ஒரு டோஸுக்கு", fCourseEnd: "கோர்ஸ் முடிவு (விருப்பம்)", fStockHint: "ஒவ்வொரு டோஸையும் டிக் செய்யும்போது தானாகக் குறையும்.",
      stockLeft: "{n} மீதம், {d} நாட்கள்", stockLeftOnly: "{n} மீதம்", courseEnds: "கோர்ஸ் முடிவு {d}", courseDone: "கோர்ஸ் முடிந்தது",
      autoTitle: "தானியங்கி வசதிகள்", autoDesc: "AushadhBox இவற்றைத் தானாகச் செய்கிறது. எச்சரிக்கைகள் போன் அறிவிப்பாக வரும்; அறிவிப்பு அணைந்திருந்தால் மின்னஞ்சலில்.",
      autoRefill: "மருந்து தீரும் முன் மீண்டும் வாங்க எச்சரிக்கை", autoMissed: "டோஸ் டிக் செய்யப்படாவிட்டால் குடும்பத்துக்குத் தெரிவி", autoCourse: "கோர்ஸ் முடிந்ததும் நினைவூட்டல்களை நிறுத்து",
      autoCleanup: "மாதந்தோறும் காலாவதியான மருந்துகளின் பட்டியல்", daysBefore: "{d} நாட்கள் முன்", minutesAfter: "டோஸ் நேரத்துக்கு {m} நிமிடம் பின்",
      calTitle: "போன் காலெண்டரில் டோஸ்கள்", calDesc: "ஒவ்வொரு டோஸ் நேரமும் அலாரத்துடன். மருந்துகள் மாறினால் தானாகப் புதுப்பிக்கும்.",
      calGoogle: "Google Calendar-இல் சேர்", calApple: "iPhone Calendar-இல் சேர்", calCopy: "இணைப்பை நகலெடு", calRenew: "புதிய தனிப்பட்ட இணைப்பு", calCopied: "இணைப்பு நகலெடுக்கப்பட்டது"
    },
    gu: {
      fStock: "બાકી ગોળીઓ", fPerDose: "દરેક ડોઝમાં", fCourseEnd: "કોર્સ પૂરો (વૈકલ્પિક)", fStockHint: "દરેક ડોઝ પર ટિક કરતાં આપમેળે ઘટે છે.",
      stockLeft: "{n} બાકી, {d} દિવસ", stockLeftOnly: "{n} બાકી", courseEnds: "કોર્સ {d}એ પૂરો", courseDone: "કોર્સ પૂરો થયો",
      autoTitle: "ઓટોમેશન", autoDesc: "AushadhBox આ કામ આપમેળે કરે છે. અલર્ટ ફોન સૂચનાથી આવે છે, સૂચના બંધ હોય તો ઈ-મેલથી.",
      autoRefill: "દવા ખૂટે તે પહેલાં ફરી ખરીદવાનો અલર્ટ", autoMissed: "ડોઝ ટિક ન થાય તો પરિવારને જણાવો", autoCourse: "કોર્સ પૂરો થાય ત્યારે રિમાઇન્ડર બંધ કરો",
      autoCleanup: "દર મહિને એક્સપાયર દવાઓની યાદી", daysBefore: "{d} દિવસ પહેલાં", minutesAfter: "ડોઝના સમય પછી {m} મિનિટ",
      calTitle: "ફોન કૅલેન્ડરમાં ડોઝ", calDesc: "દરેક ડોઝનો સમય અલાર્મ સાથે. દવાઓ બદલાય ત્યારે આપમેળે અપડેટ થાય છે.",
      calGoogle: "Google Calendar માં ઉમેરો", calApple: "iPhone Calendar માં ઉમેરો", calCopy: "લિંક કૉપિ કરો", calRenew: "નવી ખાનગી લિંક બનાવો", calCopied: "લિંક કૉપિ થઈ"
    },
    kn: {
      fStock: "ಉಳಿದ ಮಾತ್ರೆಗಳು", fPerDose: "ಪ್ರತಿ ಡೋಸ್‌ಗೆ", fCourseEnd: "ಕೋರ್ಸ್ ಮುಕ್ತಾಯ (ಐಚ್ಛಿಕ)", fStockHint: "ಪ್ರತಿ ಡೋಸ್ ಟಿಕ್ ಮಾಡಿದಾಗ ತಾನಾಗಿಯೇ ಕಡಿಮೆಯಾಗುತ್ತದೆ.",
      stockLeft: "{n} ಉಳಿದಿವೆ, {d} ದಿನ", stockLeftOnly: "{n} ಉಳಿದಿವೆ", courseEnds: "ಕೋರ್ಸ್ {d} ರಂದು ಮುಗಿಯುತ್ತದೆ", courseDone: "ಕೋರ್ಸ್ ಮುಗಿದಿದೆ",
      autoTitle: "ಸ್ವಯಂಚಾಲನೆ", autoDesc: "AushadhBox ಇವನ್ನು ತಾನಾಗಿಯೇ ಮಾಡುತ್ತದೆ. ಎಚ್ಚರಿಕೆಗಳು ಫೋನ್ ಅಧಿಸೂಚನೆಯಾಗಿ, ಅವು ಆಫ್ ಇದ್ದರೆ ಇ-ಮೇಲ್‌ನಲ್ಲಿ ಬರುತ್ತವೆ.",
      autoRefill: "ಔಷಧ ಮುಗಿಯುವ ಮೊದಲು ಮತ್ತೆ ಖರೀದಿಸುವ ಎಚ್ಚರಿಕೆ", autoMissed: "ಡೋಸ್ ಟಿಕ್ ಆಗದಿದ್ದರೆ ಕುಟುಂಬಕ್ಕೆ ತಿಳಿಸಿ", autoCourse: "ಕೋರ್ಸ್ ಮುಗಿದಾಗ ಜ್ಞಾಪನೆ ನಿಲ್ಲಿಸಿ",
      autoCleanup: "ಪ್ರತಿ ತಿಂಗಳು ಅವಧಿ ಮುಗಿದ ಔಷಧಗಳ ಪಟ್ಟಿ", daysBefore: "{d} ದಿನ ಮೊದಲು", minutesAfter: "ಡೋಸ್ ಸಮಯದ ನಂತರ {m} ನಿಮಿಷ",
      calTitle: "ಫೋನ್ ಕ್ಯಾಲೆಂಡರ್‌ನಲ್ಲಿ ಡೋಸ್‌ಗಳು", calDesc: "ಪ್ರತಿ ಡೋಸ್ ಸಮಯ ಅಲಾರಂ ಸಹಿತ. ಔಷಧಗಳು ಬದಲಾದಾಗ ತಾನಾಗಿಯೇ ನವೀಕರಿಸುತ್ತದೆ.",
      calGoogle: "Google Calendar‌ಗೆ ಸೇರಿಸಿ", calApple: "iPhone Calendar‌ಗೆ ಸೇರಿಸಿ", calCopy: "ಲಿಂಕ್ ನಕಲಿಸಿ", calRenew: "ಹೊಸ ಖಾಸಗಿ ಲಿಂಕ್", calCopied: "ಲಿಂಕ್ ನಕಲಾಗಿದೆ"
    },
    ml: {
      fStock: "ബാക്കിയുള്ള ഗുളികകൾ", fPerDose: "ഓരോ ഡോസിലും", fCourseEnd: "കോഴ്സ് അവസാനം (ഐച്ഛികം)", fStockHint: "ഓരോ ഡോസും ടിക്ക് ചെയ്യുമ്പോൾ തനിയെ കുറയും.",
      stockLeft: "{n} ബാക്കി, {d} ദിവസം", stockLeftOnly: "{n} ബാക്കി", courseEnds: "കോഴ്സ് {d}-ന് തീരും", courseDone: "കോഴ്സ് പൂർത്തിയായി",
      autoTitle: "ഓട്ടോമേഷൻ", autoDesc: "AushadhBox ഇവ തനിയെ ചെയ്യുന്നു. അലർട്ടുകൾ ഫോൺ അറിയിപ്പായി വരും; അറിയിപ്പ് ഓഫാണെങ്കിൽ ഇ-മെയിലായി.",
      autoRefill: "മരുന്ന് തീരുന്നതിന് മുമ്പ് വീണ്ടും വാങ്ങാൻ അലർട്ട്", autoMissed: "ഡോസ് ടിക്ക് ചെയ്തില്ലെങ്കിൽ കുടുംബത്തെ അറിയിക്കുക", autoCourse: "കോഴ്സ് തീരുമ്പോൾ ഓർമ്മപ്പെടുത്തൽ നിർത്തുക",
      autoCleanup: "എല്ലാ മാസവും കാലാവധി കഴിഞ്ഞ മരുന്നുകളുടെ പട്ടിക", daysBefore: "{d} ദിവസം മുമ്പ്", minutesAfter: "ഡോസ് സമയത്തിന് {m} മിനിറ്റ് ശേഷം",
      calTitle: "ഫോൺ കലണ്ടറിൽ ഡോസുകൾ", calDesc: "ഓരോ ഡോസ് സമയവും അലാറത്തോടെ. മരുന്നുകൾ മാറുമ്പോൾ തനിയെ പുതുക്കും.",
      calGoogle: "Google Calendar-ൽ ചേർക്കുക", calApple: "iPhone Calendar-ൽ ചേർക്കുക", calCopy: "ലിങ്ക് പകർത്തുക", calRenew: "പുതിയ സ്വകാര്യ ലിങ്ക്", calCopied: "ലിങ്ക് പകർത്തി"
    },
    pa: {
      fStock: "ਬਾਕੀ ਗੋਲੀਆਂ", fPerDose: "ਹਰ ਖ਼ੁਰਾਕ ਵਿੱਚ", fCourseEnd: "ਕੋਰਸ ਖ਼ਤਮ (ਵਿਕਲਪਿਕ)", fStockHint: "ਹਰ ਖ਼ੁਰਾਕ 'ਤੇ ਟਿਕ ਕਰਨ ਨਾਲ ਆਪਣੇ-ਆਪ ਘਟਦੀ ਹੈ।",
      stockLeft: "{n} ਬਾਕੀ, {d} ਦਿਨ", stockLeftOnly: "{n} ਬਾਕੀ", courseEnds: "ਕੋਰਸ {d} ਨੂੰ ਖ਼ਤਮ", courseDone: "ਕੋਰਸ ਪੂਰਾ",
      autoTitle: "ਆਟੋਮੇਸ਼ਨ", autoDesc: "AushadhBox ਇਹ ਕੰਮ ਆਪਣੇ-ਆਪ ਕਰਦਾ ਹੈ। ਅਲਰਟ ਫ਼ੋਨ ਸੂਚਨਾ ਰਾਹੀਂ ਆਉਂਦੇ ਹਨ, ਸੂਚਨਾ ਬੰਦ ਹੋਵੇ ਤਾਂ ਈ-ਮੇਲ ਰਾਹੀਂ।",
      autoRefill: "ਦਵਾਈ ਮੁੱਕਣ ਤੋਂ ਪਹਿਲਾਂ ਦੁਬਾਰਾ ਖ਼ਰੀਦਣ ਦਾ ਅਲਰਟ", autoMissed: "ਖ਼ੁਰਾਕ ਟਿਕ ਨਾ ਹੋਣ 'ਤੇ ਪਰਿਵਾਰ ਨੂੰ ਦੱਸੋ", autoCourse: "ਕੋਰਸ ਖ਼ਤਮ ਹੋਣ 'ਤੇ ਯਾਦ-ਦਹਾਨੀ ਬੰਦ ਕਰੋ",
      autoCleanup: "ਹਰ ਮਹੀਨੇ ਮਿਆਦ ਪੁੱਗੀਆਂ ਦਵਾਈਆਂ ਦੀ ਸੂਚੀ", daysBefore: "{d} ਦਿਨ ਪਹਿਲਾਂ", minutesAfter: "ਖ਼ੁਰਾਕ ਦੇ ਸਮੇਂ ਤੋਂ {m} ਮਿੰਟ ਬਾਅਦ",
      calTitle: "ਫ਼ੋਨ ਕੈਲੰਡਰ ਵਿੱਚ ਖ਼ੁਰਾਕਾਂ", calDesc: "ਹਰ ਖ਼ੁਰਾਕ ਦਾ ਸਮਾਂ ਅਲਾਰਮ ਨਾਲ। ਦਵਾਈਆਂ ਬਦਲਣ 'ਤੇ ਆਪਣੇ-ਆਪ ਅੱਪਡੇਟ ਹੁੰਦਾ ਹੈ।",
      calGoogle: "Google Calendar ਵਿੱਚ ਜੋੜੋ", calApple: "iPhone Calendar ਵਿੱਚ ਜੋੜੋ", calCopy: "ਲਿੰਕ ਕਾਪੀ ਕਰੋ", calRenew: "ਨਵਾਂ ਨਿੱਜੀ ਲਿੰਕ ਬਣਾਓ", calCopied: "ਲਿੰਕ ਕਾਪੀ ਹੋਇਆ"
    }
  };
  for (const k in add) window.I18N[k] = Object.assign(window.I18N[k] || {}, add[k]);
})();
