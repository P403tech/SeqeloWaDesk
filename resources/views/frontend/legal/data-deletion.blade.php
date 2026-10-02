@php
$sections = [
    [
        'n' => '01', 'title' => __('How to request deletion of your data'),
        'body' => '<p>' . __('You can have your personal data and account data permanently deleted at any time using either of the methods below:') . '</p>
        <h3>' . __('Option A — From inside your account') . '</h3>
        <p>' . __('Sign in, open Settings → Account, and choose "Delete account". This removes your profile and, if you are the workspace owner, your workspace and its data.') . '</p>
        <h3>' . __('Option B — By email request') . '</h3>
        <p>' . __('Send an email to our support team with the subject "Data deletion request" from the email address on your account. We will verify your identity and process the deletion. Contact details are at the bottom of this page.') . '</p>',
    ],
    [
        'n' => '02', 'title' => __('What gets deleted'),
        'body' => '<p>' . __('When you request deletion, we permanently remove:') . '</p>
        <ul>
            <li>' . __('Your profile (name, email, phone, password, profile photo)') . '</li>
            <li>' . __('Your workspace data — contacts, conversations and messages, templates, flows, and automations') . '</li>
            <li>' . __('Connected-channel credentials and access tokens (WhatsApp, Facebook, Instagram)') . '</li>
            <li>' . __('Analytics and activity logs tied to your account') . '</li>
        </ul>
        <p>' . __('Some records may be retained only where the law requires it (for example, tax and invoice records), and are deleted once that legal retention period ends.') . '</p>',
    ],
    [
        'n' => '03', 'title' => __('Data obtained through Meta / Facebook'),
        'body' => '<p>' . __('If you connected Facebook, Instagram, or the WhatsApp Business Platform to :brand, any data we received through those Meta APIs — such as your profile, connected Pages, ad accounts, Instagram accounts, and message data — is included in the deletion.', ['brand' => brand_name()]) . '</p>
        <p>' . __('We also revoke the access tokens we hold, so :brand can no longer access your Meta data after deletion.', ['brand' => brand_name()]) . '</p>',
    ],
    [
        'n' => '04', 'title' => __('How long it takes'),
        'body' => '<p>' . __('We process verified deletion requests within 30 days and send you a confirmation email once the deletion is complete. Backups are purged on our normal rolling backup cycle.') . '</p>',
    ],
    [
        'n' => '05', 'title' => __('Contact us'),
        'body' => '<p>' . __('For any data-deletion request or question, contact our support team via the') . ' <a href="' . url('/contact') . '">' . __('Contact page') . '</a>. ' . __('Please send the request from the email address associated with your account so we can verify it.') . '</p>',
    ],
];
@endphp

<x-frontend.legal-page
    :title="__('User Data Deletion')"
    :subtitle="__('How to request deletion of your :brand account and data, including data obtained through Meta.', ['brand' => brand_name()])"
    :updatedAt="__('September 12, 2026')"
    :effective="__('September 12, 2026')"
    :sections="$sections" />
