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
    | Default Client Owner for new portal registrations (account create / first order).
    | With a single email there is no alternation — every new ownerless client goes here.
    | Later orders reuse that customer's existing owner. Existing owners are never changed.
    */
    'rotating_client_owners' => [
        'narrido.glenn@webfocus.ph',
    ],

    /*
    | Same default assignee used only for new customer registration.
    | New service orders reuse the customer's already-assigned owner.
    */
    'rotating_sales_staff' => [
        'narrido.glenn@webfocus.ph',
    ],

    /*
    | Lead "Assigned To" options: real people (by name) who may receive leads.
    | UserSeeder also grants sales_role when the account exists.
    */
    'sales_assignees' => [
        ['email' => 'sales@webfocus.ph', 'sales_role' => 'sales_admin'],
        ['email' => 'sales.staff@webfocus.ph', 'sales_role' => 'sales_staff'],
        ['email' => 'narrido.glenn@webfocus.ph', 'sales_role' => 'sales_staff'],
        ['email' => 'myrna@webfocus.ph', 'sales_role' => 'sales_admin'],
        ['email' => 'durian.michelle@webfocus.ph', 'sales_role' => 'sales_staff'],
    ],
];
