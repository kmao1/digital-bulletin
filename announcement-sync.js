// Only signal names and random versions are shared; content stays in the PHP API.
(() => {
  const storageKey = 'campusconnect-announcement-signal';
  const eventName = 'campusconnect-announcement-change';
  const allowedSignals = ['announcements', 'hallway', 'messages'];
  const pollInterval = 3000;

  function publish(signals) {
    const notice = {
      signals: signals.filter(signal => allowedSignals.includes(signal)),
      version: window.crypto?.randomUUID?.() || Date.now() + '-' + Math.random()
    };
    try { localStorage.setItem(storageKey, JSON.stringify(notice)); } catch (error) {}
    window.dispatchEvent(new CustomEvent(eventName, { detail: notice }));
  }

  function subscribe(signals, refresh, interval = pollInterval) {
    let active = true;
    let running = false;
    let queued = false;
    const firebaseUnsubscribers = [];

    async function requestRefresh() {
      if (!active) return;
      if (running) { queued = true; return; }
      running = true;
      try { await refresh(); } catch (error) {
        // The page owns error feedback. A later signal/poll retries the API.
      } finally {
        running = false;
        if (queued) { queued = false; requestRefresh(); }
      }
    }

    function receive(notice) {
      if (Array.isArray(notice?.signals) && notice.signals.some(signal => signals.includes(signal))) {
        requestRefresh();
      }
    }
    function onStorage(event) {
      if (event.key !== storageKey || !event.newValue) return;
      try { receive(JSON.parse(event.newValue)); } catch (error) {}
    }
    const onSignal = event => receive(event.detail);
    const onVisibility = () => { if (!document.hidden) requestRefresh(); };
    window.addEventListener('storage', onStorage);
    window.addEventListener(eventName, onSignal);
    window.addEventListener('focus', requestRefresh);
    window.addEventListener('online', requestRefresh);
    document.addEventListener('visibilitychange', onVisibility);
    const timer = window.setInterval(() => {
      if (!document.hidden) requestRefresh();
    }, interval);
    requestRefresh();

    (async () => {
      try {
        const [{ realtimeDb }, { onValue, ref }] = await Promise.all([
          import('./app.js'),
          import('https://www.gstatic.com/firebasejs/10.8.0/firebase-database.js')
        ]);
        if (!active) return;
        signals.forEach(signal => {
          let previousVersion;
          firebaseUnsubscribers.push(onValue(ref(realtimeDb, 'notificationSignals/' + signal), snapshot => {
            const version = snapshot.val()?.version || null;
            if (version !== previousVersion) {
              previousVersion = version;
              requestRefresh();
            }
          }, () => { /* Always-on polling remains available when Firebase fails. */ }));
        });
      } catch (error) { /* Local signals and polling work without Firebase. */ }
    })();

    return () => {
      active = false;
      window.clearInterval(timer);
      window.removeEventListener('storage', onStorage);
      window.removeEventListener(eventName, onSignal);
      window.removeEventListener('focus', requestRefresh);
      window.removeEventListener('online', requestRefresh);
      document.removeEventListener('visibilitychange', onVisibility);
      firebaseUnsubscribers.forEach(unsubscribe => unsubscribe());
    };
  }

  window.AnnouncementSync = { publish, subscribe };
})();
