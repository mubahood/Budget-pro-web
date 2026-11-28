<?php

/*
 * Learn: training videos for every app (docs/LEARN_VIDEOS.md). The apps come from App\Support\AppFamily; an app that
 * is not there yet can be added here (slug => name) so lessons can target it before it joins AppFamily.
 */
return [
    'extra_apps' => [],

    // Uploaded lesson files and thumbnails go to public storage, like the promo videos.
    'disk' => 'admin',
    'dir' => 'learn-videos',
    'max' => ['video' => 300 * 1048576, 'thumbnail' => 5 * 1048576],

    // Seconds one progress report may add to "watched" (a phone that was asleep cannot claim an hour).
    'max_step' => 60,
    // Share of a lesson that counts as finished.
    'finished_at' => 0.9,

    // Roles that count as "members" (everyone else signed in to the books is an officer).
    'member_roles' => ['viewer', 'kin_member', 'unity_member'],

    'languages' => ['en' => 'English', 'sw' => 'Kiswahili', 'lg' => 'Luganda'],
];
