importScripts('https://www.gstatic.com/firebasejs/10.8.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.8.0/firebase-messaging-compat.js');

firebase.initializeApp({
  apiKey: 'AIzaSyBwj-9g1TS6ymhD8z3Luo0YN2fa05VTFfE',
  authDomain: 'campusconnect-7c284.firebaseapp.com',
  projectId: 'campusconnect-7c284',
  databaseURL: 'https://campusconnect-7c284-default-rtdb.firebaseio.com',
  storageBucket: 'campusconnect-7c284.firebasestorage.app',
  messagingSenderId: '191621639077',
  appId: '1:191621639077:web:b0a7e7092ddaba0e7a964c',
  measurementId: 'G-PJHEZPHX6Y'
});

const messaging = firebase.messaging();

messaging.onBackgroundMessage(payload => {
  const data = payload.data || {};
  const notification = {
    body: data.body || 'Open Campus Connect to view the update.',
    icon: data.icon || 'bcc.jpg',
    badge: 'IS.jpg',
    data: { url: data.url || 'student-dashboard.html', view: data.view || '' }
  };
  if (data.announcementId) notification.tag = 'announcement-' + data.announcementId;
  self.registration.showNotification(data.title || 'Campus Connect', notification);
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const data = event.notification.data || {};
  const target = new URL(data.url || 'student-dashboard.html', self.location.href);
  if (data.view === 'messages-view') target.searchParams.set('view', data.view);
  const targetUrl = target.href;
  event.waitUntil((async () => {
    const clientsList = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of clientsList) {
      if (new URL(client.url).origin === self.location.origin) {
        await client.focus();
        if (client.url !== targetUrl) await client.navigate(targetUrl);
        return;
      }
    }
    await clients.openWindow(targetUrl);
  })());
});
