# Backend

The API, realtime (Reverb) and push (FCM) now live in a **separate project**:

```
../supernative_server
```

This project (`supernative_messenger`) is the **NativePHP mobile client** only.
It talks to the server over HTTP via `MESSENGER_API_URL`:

```
# .env
MESSENGER_API_URL=https://your-api.example.com   # blank = fully offline
```

When `MESSENGER_API_URL` is blank the app runs entirely against its local
SQLite mirror — every screen still works, it just doesn't sync.

See `../supernative_server/README.md` for running and deploying the API.
