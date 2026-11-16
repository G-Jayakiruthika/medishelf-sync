// Interface words in English, Tamil and Hindi. Missing words fall back to English.
import type { Lang } from './types';

const en = {
  today: 'Today', cabinet: 'Cabinet', family: 'Family', health: 'Health', visits: 'Doctor visits', expenses: 'Expenses',
  privacy: 'Privacy', settings: 'Settings', signOut: 'Sign out', more: 'More',
  goodMorning: 'Good morning', goodAfternoon: 'Good afternoon', goodEvening: 'Good evening',
  m: 'Morning', a: 'Afternoon', e: 'Evening', b: 'Bedtime',
  taken: 'Taken', skipped: 'Skipped', notYet: 'Not yet', take: 'Mark taken', skip: 'Skip', undo: 'Undo',
  dosesToday: 'Doses today', expiringSoon: 'Expiring soon', expired: 'Expired', lowStock: 'Running low', upcoming: 'Coming up',
  adherence7: 'Taken on time, last 7 days', addMedicine: 'Add medicine', addPerson: 'Add family member', add: 'Add', save: 'Save',
  cancel: 'Cancel', delete: 'Delete', edit: 'Edit', search: 'Search', all: 'All', wholeFamily: 'Whole family',
  name: 'Name', strength: 'Strength', form: 'Form', expiry: 'Expiry', batch: 'Batch', doseTimes: 'When to take', perDose: 'Per dose',
  stock: 'Left in stock', courseEnd: 'Course ends', food: 'Food', notes: 'Notes', forWhom: 'For',
  nothingDue: 'No doses scheduled today.', allDone: 'All of today’s doses are confirmed.',
  demoBanner: 'Demo mode: sample family, saved only in this browser.',
  language: 'Language',
};
export type Key = keyof typeof en;

const ta: Partial<Record<Key, string>> = {
  today: 'இன்று', cabinet: 'மருந்துப் பெட்டி', family: 'குடும்பம்', health: 'உடல்நலம்', visits: 'மருத்துவர் சந்திப்பு', expenses: 'செலவுகள்',
  privacy: 'தனியுரிமை', settings: 'அமைப்புகள்', signOut: 'வெளியேறு', more: 'மேலும்',
  goodMorning: 'காலை வணக்கம்', goodAfternoon: 'மதிய வணக்கம்', goodEvening: 'மாலை வணக்கம்',
  m: 'காலை', a: 'மதியம்', e: 'மாலை', b: 'இரவு',
  taken: 'எடுத்தாச்சு', skipped: 'தவிர்க்கப்பட்டது', notYet: 'இன்னும் இல்லை', take: 'எடுத்தேன்', skip: 'தவிர்', undo: 'மாற்று',
  dosesToday: 'இன்றைய மருந்துகள்', expiringSoon: 'விரைவில் காலாவதி', expired: 'காலாவதியானது', lowStock: 'குறைவாக உள்ளது', upcoming: 'வரவிருப்பவை',
  addMedicine: 'மருந்து சேர்', addPerson: 'உறுப்பினர் சேர்', add: 'சேர்', save: 'சேமி', cancel: 'ரத்து', delete: 'நீக்கு', edit: 'திருத்து',
  search: 'தேடு', all: 'அனைத்தும்', wholeFamily: 'முழு குடும்பம்', name: 'பெயர்', expiry: 'காலாவதி', doseTimes: 'எப்போது எடுக்க வேண்டும்',
  forWhom: 'யாருக்கு', nothingDue: 'இன்று மருந்துகள் இல்லை.', allDone: 'இன்றைய அனைத்து மருந்துகளும் உறுதி செய்யப்பட்டன.', language: 'மொழி',
};

const hi: Partial<Record<Key, string>> = {
  today: 'आज', cabinet: 'दवा बॉक्स', family: 'परिवार', health: 'सेहत', visits: 'डॉक्टर विज़िट', expenses: 'खर्च',
  privacy: 'गोपनीयता', settings: 'सेटिंग्स', signOut: 'साइन आउट', more: 'और',
  goodMorning: 'सुप्रभात', goodAfternoon: 'नमस्कार', goodEvening: 'शुभ संध्या',
  m: 'सुबह', a: 'दोपहर', e: 'शाम', b: 'रात',
  taken: 'ले ली', skipped: 'छोड़ी', notYet: 'अभी नहीं', take: 'ले ली', skip: 'छोड़ें', undo: 'वापस',
  dosesToday: 'आज की दवाएँ', expiringSoon: 'जल्द एक्सपायर', expired: 'एक्सपायर', lowStock: 'कम बची', upcoming: 'आने वाले',
  addMedicine: 'दवा जोड़ें', addPerson: 'सदस्य जोड़ें', add: 'जोड़ें', save: 'सहेजें', cancel: 'रद्द करें', delete: 'हटाएँ', edit: 'बदलें',
  search: 'खोजें', all: 'सभी', wholeFamily: 'पूरा परिवार', name: 'नाम', expiry: 'एक्सपायरी', doseTimes: 'कब लेनी है',
  forWhom: 'किसके लिए', nothingDue: 'आज कोई दवा नहीं।', allDone: 'आज की सभी दवाएँ पूरी हुईं।', language: 'भाषा',
};

const table: Record<Lang, Partial<Record<Key, string>>> = { en, ta, hi };
let current: Lang = 'en';

export function setLang(l: Lang) {
  current = l;
  document.documentElement.lang = l;
}
export function t(k: Key): string { return table[current][k] ?? en[k]; }
export const LANGS: { code: Lang; label: string }[] = [
  { code: 'en', label: 'English' }, { code: 'ta', label: 'தமிழ்' }, { code: 'hi', label: 'हिन्दी' },
];
