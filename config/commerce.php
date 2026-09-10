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
    | These two staff accounts take turns as Client Owner / Sales Staff
    | only when a customer first registers. Later orders keep that same owner.
    | Change the emails here to swap the pair.
    */
    'rotating_client_owners' => [
        'myrna@webfocus.ph',
        'durian.michelle@webfocus.ph',
    ],

    /*
    | Same rotating pair used only for new customer registration.
    | New service orders reuse the customer's already-assigned owner.
    */
    'rotating_sales_staff' => [
        'myrna@webfocus.ph',
        'durian.michelle@webfocus.ph',
    ],
];
