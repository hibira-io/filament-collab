<?php

return [

    /*
    | How long a user may keep editing a document after the page that shows
    | the editor was rendered, in minutes.
    */
    'grant_minutes' => (int) env('HIBIRA_COLLAB_GRANT_MINUTES', 720),

    /*
    | The largest change one request may send, in bytes of Yjs update. A
    | pasted chapter is about 100 KB.
    */
    'max_update_bytes' => (int) env('HIBIRA_COLLAB_MAX_UPDATE_BYTES', 1048576),

    /*
    | After this many stored changes, the next editor to save replaces them
    | with one snapshot.
    */
    'compact_after' => (int) env('HIBIRA_COLLAB_COMPACT_AFTER', 200),

    /*
    | Changes larger than this are announced without their content and the
    | editors fetch them. Hibira's Free plan allows 4 KB per event.
    */
    'max_event_bytes' => (int) env('HIBIRA_COLLAB_MAX_EVENT_BYTES', 3500),

    /*
    | Database connection and table names.
    */
    'connection' => env('HIBIRA_COLLAB_DB_CONNECTION'),

    'tables' => [
        'documents' => 'hibira_collab_documents',
        'updates' => 'hibira_collab_updates',
    ],

];
