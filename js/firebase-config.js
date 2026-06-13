// ================================================================
//  Firebase — конфигурация
// ================================================================
//
//  ШАГ 1: Создай проект на https://console.firebase.google.com
//
//  ШАГ 2: Project Settings (шестерёнка) → Your apps → </> (Web)
//          Дай приложению имя, нажми "Register app"
//          Скопируй объект firebaseConfig и вставь ниже
//
//  ШАГ 3: Build → Realtime Database → Create database
//          Выбери регион, запусти в тестовом режиме (потом поправим правила)
//
//  ШАГ 4: Build → Authentication → Get started
//          Sign-in method → Google → Enable → Save
//
//  ШАГ 5: Заполни поля ниже и открой сайт, нажми "Войти"
//          В браузере появится popup — твой UID будет показан
//          Вставь UID в ADMIN_UID и задеплой снова
//
//  ШАГ 6: Realtime Database → Rules — вставь и опубликуй:
//  {
//    "rules": {
//      ".read": true,
//      ".write": "auth != null && auth.uid === 'ВАШ_UID'"
//    }
//  }
// ================================================================

const FIREBASE_CONFIG = {
  apiKey:            "AIzaSyDbxRZK5rGRfv8iDJoiPosOS4Tw0h-4hbw",
  authDomain:        "nexus-117f0.firebaseapp.com",
  databaseURL:       "https://nexus-117f0-default-rtdb.firebaseio.com",
  projectId:         "nexus-117f0",
  storageBucket:     "nexus-117f0.firebasestorage.app",
  messagingSenderId: "928095415814",
  appId:             "1:928095415814:web:27b4da6d4b952819da1d87"
};

// Твой Google UID — оставь пустым при первом входе.
// После входа появится окошко с UID — вставь его сюда.
const ADMIN_UID = "K0wFqjgmfMN6noIKqfWKf45brQ03";
