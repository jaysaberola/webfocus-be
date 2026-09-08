<?php

return [
    /*
    | The staff accounts that appear in Client Owner picklists.
    | Order here is the order shown in the UI.
    */
    'client_owners' => [
        ['email' => 'admin@webfocus.ph', 'fname' => 'Administrator - Webfocus Solutions Inc', 'lname' => '', 'role' => 'admin'],
        ['email' => 'narrido.glenn@webfocus.ph', 'fname' => 'Glenn', 'lname' => 'Narrido', 'role' => 'customer_care'],
        ['email' => 'myrna@webfocus.ph', 'fname' => 'Myrna', 'lname' => 'Glorioso', 'role' => 'customer_care'],
        ['email' => 'customercare@webfocus.ph', 'fname' => 'Customer Care WSI', 'lname' => '', 'role' => 'customer_care'],
        ['email' => 'durian.michelle@webfocus.ph', 'fname' => 'Michelle', 'lname' => 'Durian', 'role' => 'customer_care'],
        ['email' => 'rcpeazoho@webfocus.ph', 'fname' => 'RCPEA', 'lname' => '', 'role' => 'customer_care'],
    ],

    /*
    | These two staff accounts take turns as Client Owner on each new order
    | from the same client. Change the emails here to swap the pair.
    | Order 1 → first person, order 2 → second person, order 3 → first, and so on.
    */
    'rotating_client_owners' => [
        'myrna@webfocus.ph',
        'durian.michelle@webfocus.ph',
    ],

    /*
    | New web design / web development orders rotate Client Owner between
    | these two staff accounts only. Same pair as rotating_client_owners.
    */
    'rotating_sales_staff' => [
        'myrna@webfocus.ph',
        'durian.michelle@webfocus.ph',
    ],
];
