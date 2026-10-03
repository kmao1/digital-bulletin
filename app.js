
// Firebase browser configuration.
import { initializeApp } from "https://www.gstatic.com/firebasejs/10.8.0/firebase-app.js";
import { getDatabase } from "https://www.gstatic.com/firebasejs/10.8.0/firebase-database.js";

const firebaseConfig = {
    apiKey: "AIzaSyBwj-9g1TS6ymhD8z3Luo0YN2fa05VTFfE",
    authDomain: "campusconnect-7c284.firebaseapp.com",
    projectId: "campusconnect-7c284",
    databaseURL: "https://campusconnect-7c284-default-rtdb.firebaseio.com",
    storageBucket: "campusconnect-7c284.firebasestorage.app",
    messagingSenderId: "191621639077",
    appId: "1:191621639077:web:b0a7e7092ddaba0e7a964c",
    measurementId: "G-PJHEZPHX6Y"
};

const app = initializeApp(firebaseConfig);
export const firebaseApp = app;
export const realtimeDb = getDatabase(app);