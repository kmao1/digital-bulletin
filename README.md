# Campus Connect

Campus Connect is a PHP/MySQL portal with a Firebase Realtime Database signal channel for live student updates. The existing MySQL database remains the source of truth for accounts, audience targeting, announcements, direct messages, and the hallway bulletin.

## Local Setup

1. Start Apache and MySQL in XAMPP.
2. Select the `dsb` database in phpMyAdmin. For a clean install, import `database-schema.sql`; for an existing install, keep your data and let `conn.php` create the newer announcement/realtime tables.
3. Configure PHP environment variables before starting Apache. Set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` for the database.
4. For a new database, set `INITIAL_ADMIN_NAME`, `INITIAL_ADMIN_EMAIL`, and `INITIAL_ADMIN_PASSWORD` before the first request. Optionally set `INITIAL_OFFICER_NAME`, `INITIAL_OFFICER_EMAIL`, and `INITIAL_OFFICER_PASSWORD`. The server hashes these passwords and creates an account only when that email is not already present. Remove the bootstrap variables after the accounts exist.
5. Open `http://localhost/BULLETIN/`.

Admin and officer sign-in use the password hashes in the MySQL `admin` and `officer` tables. Student registration and sign-in use the `student` table. Firebase Authentication is not used for these portal roles. Bootstrap variables never reset existing accounts; to provision another admin beside an existing account, use a new email address.

## Firebase Realtime Updates

1. In Firebase Console, open project `campusconnect-7c284` and create its Realtime Database in the region you choose. The configured default URL is `https://campusconnect-7c284-default-rtdb.firebaseio.com`.
2. In **Project settings → Cloud Messaging → Web Push certificates**, generate a key pair. Set its public key as `FIREBASE_WEB_PUSH_PUBLIC_KEY`; this is safe to return to the browser. Also set `FIREBASE_DATABASE_URL` and `FIREBASE_SERVICE_ACCOUNT_PATH`. Store the service-account JSON outside the public web root; never commit it or place it in `htdocs`.
3. From a trusted Firebase CLI session, deploy the included read-only signal rules with `firebase deploy --only database`.

Realtime Database contains only random versions and timestamps under `notificationSignals`. The signal paths are readable by clients and not writable by clients. After a signal changes, each signed-in student fetches their own announcements/messages from the PHP API; private message text and audience-targeted notices are not stored in public Firebase paths. If Firebase is unavailable, the student dashboard falls back to periodic refresh.

Students must sign in and tap **Enable alerts** once on each phone/browser. The browser asks permission and saves its FCM token to the signed-in student account. New announcements send to only tokens matching the announcement audience; private messages send a generic alert to the recipient, never the message contents. The service worker displays alerts when the dashboard is closed. If FCM is unavailable, in-page realtime updates use the Firebase Database signal and then polling fallback.

Web notifications require HTTPS (localhost is allowed for development). iPhone/iPad users must first use Safari's **Share → Add to Home Screen**, open the installed Campus Connect icon, then enable alerts inside the student portal. Android users can enable alerts from a supported HTTPS browser. If the VAPID key is unset, the student button reports that push setup is needed.

The local XAMPP setup has a Realtime Database instance and a server-to-Firebase signal write has been verified. FCM phone delivery additionally requires the Web Push public key above and the Firebase Cloud Messaging API to be enabled for the project.

## Free Public Hosting

Firebase Hosting serves static files and cannot execute this app's PHP endpoints or connect directly to its MySQL database. To publish the complete system without a hosting bill, use a free PHP/MySQL host that supports HTTPS, `mysqli`, `curl`, `openssl`, and outbound HTTPS requests to Google APIs. A free shared host such as InfinityFree may be an option; confirm its current PHP, database, and outbound-request limits before choosing it.

1. Export the existing MySQL database and import it into the host-provided database.
2. Upload the site files and assets to the host's public web directory.
3. Set the database environment variables in the host control panel. Do not use the local XAMPP `root` account in production.
4. Store the Firebase service-account file outside the public directory and set its path as `FIREBASE_SERVICE_ACCOUNT_PATH`. Set `FIREBASE_WEB_PUSH_PUBLIC_KEY` from Firebase's Web Push certificates as well.
5. Use the host's HTTPS URL. The public Firebase read rule exposes only signal versions/timestamps, never message text or student records.
6. Test student, officer, and admin sign-in; targeted announcement visibility; direct messages; Firebase live updates; and the polling fallback.

The app has not been published to a public host: that requires control of a hosting account and Firebase Console, which are not available from this workspace. Free-host quotas and availability are controlled by the provider and are not guaranteed by this project.
