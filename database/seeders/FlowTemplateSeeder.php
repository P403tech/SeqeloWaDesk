<?php

namespace Database\Seeders;

use App\Models\FlowTemplate;
use Illuminate\Database\Seeder;

/**
 * Industry gallery blueprints on /flows ("Start from a template").
 *
 * Copy is Meta-policy-safe: inbound keyword triggers (customer-initiated
 * 24h session), UTILITY-style wording, no unsolicited marketing. A
 * `template` node is included where an outside-window follow-up needs a
 * Meta-approved WhatsApp template the tenant maps in the builder.
 *
 * Run: php artisan db:seed --class=Database\\Seeders\\FlowTemplateSeeder
 */
class FlowTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            $this->supportDesk(),
            $this->faqMenu(),
            $this->optInLeadCapture(),
            $this->orderStatus(),
            $this->appointmentBooking(),
            $this->restaurantDesk(),
            $this->courseEnquiry(),
            $this->loanStatus(),
            $this->tripItinerary(),
            $this->saasOnboarding(),
            $this->agencyIntake(),
            $this->propertyViewing(),
            $this->salonBooking(),
            $this->parcelTracking(),
        ];

        foreach ($templates as $i => $tpl) {
            $row = FlowTemplate::query()->where('name', $tpl['name'])->first() ?: new FlowTemplate();
            $row->fill([
                'name'        => $tpl['name'],
                'description' => $tpl['description'],
                'flow_type'   => 'chat',
                'category'    => $tpl['category'],
                'flow_data'   => $tpl['graph'],
                'is_active'   => true,
                'sort_order'  => $i + 1,
            ]);
            $row->save();
            $this->command?->info('  • '.$tpl['name'].' ('.count($tpl['graph']['flowNodes']).' steps)');
        }

        $this->command?->info('[FlowTemplate] '.count($templates).' industry Meta-compliant chat templates ready.');
    }

    private function node(int $i, string $type, string $id, array $data, array $extra = []): array
    {
        $perRow = 4;
        $dx = 280;
        $dy = 170;

        return array_merge([
            'id'   => $id,
            'type' => $type,
            'x'    => 80 + ($i % $perRow) * $dx,
            'y'    => 80 + intdiv($i, $perRow) * $dy,
            'data' => $data,
        ], $extra);
    }

    private function edge(string $from, ?string $handle, string $to): array
    {
        $e = [
            'id'     => 'e_'.$from.'_'.($handle ?: 'out').'_'.$to,
            'source' => $from,
            'target' => $to,
        ];
        if ($handle !== null) {
            $e['sourceHandle'] = $handle;
        }

        return $e;
    }

    private function graph(array $nodes, array $edges): array
    {
        return ['flowNodes' => $nodes, 'flowEdges' => $edges, 'vars' => []];
    }

    /** Customer-initiated support — session messages only. */
    private function supportDesk(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'help, support, hi, hello',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'welcome', [
            'text' => "Hi {{name}} — thanks for messaging us.\nHow can we help you today?",
        ]);
        $n[] = $this->node($i++, 'buttons', 'menu', [
            'prompt'  => 'Choose an option:',
            'options' => ['Order help', 'Talk to a person', 'Something else'],
            'var'     => 'support_topic',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_order', [
            'prompt' => 'Please reply with your order number so we can look it up.',
            'var'    => 'order_id', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'order_ack', [
            'text' => 'Thanks. We have order {{order_id}} and a teammate will follow up in this chat.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign_agent', [
            'team' => '', 'userId' => '', 'message' => 'Inbound support — order {{order_id}}',
        ]);
        $n[] = $this->node($i++, 'message', 'handoff', [
            'text' => 'Connecting you with a teammate now. Please stay in this chat.',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_detail', [
            'prompt' => 'Please describe what you need help with in one message.',
            'var'    => 'support_detail', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'assign', 'assign_other', [
            'team' => '', 'userId' => '', 'message' => 'Support: {{support_detail}}',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'welcome'),
            $this->edge('welcome', 'out', 'menu'),
            $this->edge('menu', 'p0', 'ask_order'),
            $this->edge('menu', 'p1', 'handoff'),
            $this->edge('menu', 'p2', 'ask_detail'),
            $this->edge('ask_order', 'out', 'order_ack'),
            $this->edge('order_ack', 'out', 'assign_agent'),
            $this->edge('assign_agent', 'out', 'end'),
            $this->edge('handoff', 'out', 'assign_agent'),
            $this->edge('ask_detail', 'out', 'assign_other'),
            $this->edge('assign_other', 'out', 'end'),
        ];

        return [
            'name'        => 'Support desk (Meta utility)',
            'description' => 'Customer-initiated help menu. Session messages only — map your Meta-approved utility template later for out-of-window follow-up.',
            'category'    => 'support',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Order / shipment status — typical UTILITY category Meta approves. */
    private function orderStatus(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'order, tracking, status, shipped',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'We can share the latest status of your order in this chat.',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_order', [
            'prompt' => 'Reply with your order number (for example WD-1042).',
            'var'    => 'order_id', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'ack', [
            'text' => "Thanks. We're checking order {{order_id}} and will reply here with the status.",
        ]);
        $n[] = $this->node($i++, 'list', 'next', [
            'prompt'  => 'What do you need next?',
            'button'  => 'View options',
            'var'     => 'order_next',
            'options' => [
                ['title' => 'Tracking link', 'description' => 'Get the carrier tracking details', 'section' => 'Order'],
                ['title' => 'Change address', 'description' => 'Update delivery details', 'section' => 'Order'],
                ['title' => 'Talk to support', 'description' => 'A teammate will join this chat', 'section' => 'Help'],
            ],
        ]);
        $n[] = $this->node($i++, 'message', 'track_msg', [
            'text' => 'A teammate will send the tracking details for {{order_id}} in this chat shortly.',
        ]);
        $n[] = $this->node($i++, 'message', 'address_msg', [
            'text' => 'Reply with the updated delivery address for order {{order_id}} and we will confirm it here.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Order status {{order_id}} / {{order_next}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_followup', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY template (e.g. order_shipped) for messages outside the 24h window.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'ask_order'),
            $this->edge('ask_order', 'out', 'ack'),
            $this->edge('ack', 'out', 'next'),
            $this->edge('next', 'p0', 'track_msg'),
            $this->edge('next', 'p1', 'address_msg'),
            $this->edge('next', 'p2', 'assign'),
            $this->edge('track_msg', 'out', 'assign'),
            $this->edge('address_msg', 'out', 'assign'),
            $this->edge('assign', 'out', 'tpl_followup'),
            $this->edge('tpl_followup', 'out', 'end'),
        ];

        return [
            'name'        => 'Order status (Meta utility)',
            'description' => 'Tracking and order updates. Uses session replies; includes a template node for a Meta-approved shipping/utility template.',
            'category'    => 'ecommerce',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Appointments — UTILITY reminder/confirm pattern. */
    private function appointmentBooking(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'book, appointment, schedule, booking',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'We can book or update an appointment in this chat.',
        ]);
        $n[] = $this->node($i++, 'buttons', 'menu', [
            'prompt'  => 'What would you like to do?',
            'options' => ['Book a time', 'Reschedule', 'Cancel'],
            'var'     => 'appt_action',
        ]);
        $n[] = $this->node($i++, 'book_appointment', 'book', [
            'slotCount' => 5,
            'prompt'    => 'Pick a time that works:',
            'confirmation' => 'Your appointment is confirmed for {{slot}}.',
            'calendarOverride' => '',
            'collectEmail' => true,
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_current', [
            'prompt' => 'Reply with your current appointment date and time so we can update it.',
            'var'    => 'current_slot', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'cancel_ack', [
            'text' => 'Your appointment request has been received. We will confirm the cancellation in this chat.',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_reminder', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY appointment_reminder template for the reminder send.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'book'),
            $this->edge('menu', 'p1', 'ask_current'),
            $this->edge('menu', 'p2', 'cancel_ack'),
            $this->edge('book', 'out', 'tpl_reminder'),
            $this->edge('ask_current', 'out', 'book'),
            $this->edge('cancel_ack', 'out', 'end'),
            $this->edge('tpl_reminder', 'out', 'end'),
        ];

        return [
            'name'        => 'Appointments (Meta utility)',
            'description' => 'Book, reschedule, or cancel. Session chat plus a slot for your Meta-approved appointment reminder template.',
            'category'    => 'healthcare',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Explicit opt-in capture — required before marketing templates. */
    private function optInLeadCapture(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'info, catalogue, catalog, updates',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'notice', [
            'text' => "We can send useful updates on WhatsApp about your account and orders.\nReply YES if you agree to receive these messages. Reply STOP any time to opt out.",
        ]);
        $n[] = $this->node($i++, 'buttons', 'consent', [
            'prompt'  => 'Do you want updates in this chat?',
            'options' => ['Yes, keep me updated', 'No thanks'],
            'var'     => 'opt_in',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_name', [
            'prompt' => 'What name should we use?',
            'var'    => 'lead_name', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_email', [
            'prompt' => 'What email should we use for receipts?',
            'var'    => 'lead_email', 'validate' => 'email', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'tag', 'tag_optin', [
            'action' => 'add', 'tagId' => '', 'tag' => 'whatsapp-opt-in',
        ]);
        $n[] = $this->node($i++, 'message', 'thanks', [
            'text' => 'Thanks {{lead_name}}. You are opted in. Reply STOP any time to unsubscribe.',
        ]);
        $n[] = $this->node($i++, 'message', 'declined', [
            'text' => 'No problem. We will only reply in this chat when you message us.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'notice'),
            $this->edge('notice', 'out', 'consent'),
            $this->edge('consent', 'p0', 'ask_name'),
            $this->edge('consent', 'p1', 'declined'),
            $this->edge('ask_name', 'out', 'ask_email'),
            $this->edge('ask_email', 'out', 'tag_optin'),
            $this->edge('tag_optin', 'out', 'thanks'),
            $this->edge('thanks', 'out', 'end'),
            $this->edge('declined', 'out', 'end'),
        ];

        return [
            'name'        => 'WhatsApp opt-in (Meta compliant)',
            'description' => 'Collects explicit YES/STOP consent before any marketing template. Required for Meta-approved MARKETING sends.',
            'category'    => 'lead',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** FAQ list — session interactive messages Meta allows in-thread. */
    private function faqMenu(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'faq, hours, price, pricing, location',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'list', 'faq', [
            'prompt'  => 'What would you like to know?',
            'button'  => 'Open menu',
            'var'     => 'faq_topic',
            'options' => [
                ['title' => 'Opening hours', 'description' => 'When we are available', 'section' => 'Basics'],
                ['title' => 'Pricing', 'description' => 'How billing works', 'section' => 'Basics'],
                ['title' => 'Our location', 'description' => 'Address and map', 'section' => 'Basics'],
                ['title' => 'Talk to a person', 'description' => 'A teammate will join', 'section' => 'Help'],
            ],
        ]);
        $n[] = $this->node($i++, 'message', 'hours', [
            'text' => 'We reply on WhatsApp every day, 9:00–18:00 (your local timezone).',
        ]);
        $n[] = $this->node($i++, 'message', 'pricing', [
            'text' => 'Pricing depends on your plan. A teammate can confirm the exact amount for your account in this chat.',
        ]);
        $n[] = $this->node($i++, 'location', 'location', [
            'lat' => 25.2048, 'lng' => 55.2708, 'address' => 'Update this pin in the builder', 'title' => 'Our office',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'FAQ handoff',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'faq'),
            $this->edge('faq', 'p0', 'hours'),
            $this->edge('faq', 'p1', 'pricing'),
            $this->edge('faq', 'p2', 'location'),
            $this->edge('faq', 'p3', 'assign'),
            $this->edge('hours', 'out', 'end'),
            $this->edge('pricing', 'out', 'end'),
            $this->edge('location', 'out', 'end'),
            $this->edge('assign', 'out', 'end'),
        ];

        return [
            'name'        => 'FAQ menu (session)',
            'description' => 'Hours, pricing, location, and handoff using WhatsApp list messages inside the customer-initiated window.',
            'category'    => 'support',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Hospitality — restaurant table / hours. */
    private function restaurantDesk(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'table, reservation, menu, book, restaurant',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => "Hi {{name}} — thanks for messaging us.\nWe can reserve a table or share today's hours in this chat.",
        ]);
        $n[] = $this->node($i++, 'buttons', 'menu', [
            'prompt'  => 'How can we help?',
            'options' => ['Reserve a table', 'Opening hours', 'Talk to the host'],
            'var'     => 'resto_need',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_party', [
            'prompt' => 'How many guests should we seat?',
            'var'    => 'party_size', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'book_appointment', 'book', [
            'slotCount' => 6,
            'prompt'    => 'Pick a seating time:',
            'confirmation' => 'Table reserved for {{party_size}} guests at {{slot}}.',
            'calendarOverride' => '',
            'collectEmail' => false,
        ]);
        $n[] = $this->node($i++, 'message', 'hours', [
            'text' => 'We are open 12:00–15:00 and 18:00–22:30. Kitchen last orders 30 minutes before close. Update these hours in the builder.',
        ]);
        $n[] = $this->node($i++, 'location', 'location', [
            'lat' => 25.2048, 'lng' => 55.2708, 'address' => 'Update this pin in the builder', 'title' => 'Restaurant',
        ]);
        $n[] = $this->node($i++, 'message', 'no_slots', [
            'text' => 'No open tables match that time. A host will confirm the next available seating in this chat.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Restaurant: {{resto_need}} · party {{party_size}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_reminder', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY reservation_reminder template for the reminder send.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'ask_party'),
            $this->edge('menu', 'p1', 'hours'),
            $this->edge('menu', 'p2', 'assign'),
            $this->edge('ask_party', 'out', 'book'),
            $this->edge('book', 'booked', 'tpl_reminder'),
            $this->edge('book', 'no_slots', 'no_slots'),
            $this->edge('no_slots', 'out', 'assign'),
            $this->edge('hours', 'out', 'location'),
            $this->edge('location', 'out', 'end'),
            $this->edge('assign', 'out', 'end'),
            $this->edge('tpl_reminder', 'out', 'end'),
        ];

        return [
            'name'        => 'Restaurant desk (hospitality)',
            'description' => 'Table reservation, hours, and host handoff. Session chat plus a slot for a Meta-approved reservation reminder template.',
            'category'    => 'hospitality',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Education — course / admission enquiry. */
    private function courseEnquiry(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'course, enroll, class, admission, fees',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'We can share programme details and next steps for enrolment in this chat.',
        ]);
        $n[] = $this->node($i++, 'list', 'menu', [
            'prompt'  => 'What would you like to know?',
            'button'  => 'View options',
            'var'     => 'edu_topic',
            'options' => [
                ['title' => 'Programmes', 'description' => 'What we currently offer', 'section' => 'Study'],
                ['title' => 'Fees & dates', 'description' => 'Tuition and start dates', 'section' => 'Study'],
                ['title' => 'Apply / enroll', 'description' => 'Share your details for a callback', 'section' => 'Admissions'],
                ['title' => 'Talk to admissions', 'description' => 'A teammate will join', 'section' => 'Help'],
            ],
        ]);
        $n[] = $this->node($i++, 'message', 'programmes', [
            'text' => 'Update this list in the builder with your current programmes. A teammate can confirm eligibility in this chat.',
        ]);
        $n[] = $this->node($i++, 'message', 'fees', [
            'text' => 'Fees and start dates depend on the programme. Reply with the course name and we will confirm the current figures here.',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_name', [
            'prompt' => 'What name should admissions use?',
            'var'    => 'lead_name', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_email', [
            'prompt' => 'What email should we use for enrolment updates?',
            'var'    => 'lead_email', 'validate' => 'email', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'deal', 'deal', [
            'action' => 'create', 'dealName' => '{{lead_name}} — enrolment',
            'stageId' => '', 'value' => '', 'ownerId' => '', 'saveAs' => 'deal_id',
        ]);
        $n[] = $this->node($i++, 'message', 'thanks', [
            'text' => 'Thanks {{lead_name}}. Admissions has your request and will follow up in this chat.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Education enquiry: {{edu_topic}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_class', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY class_reminder template for session reminders.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'programmes'),
            $this->edge('menu', 'p1', 'fees'),
            $this->edge('menu', 'p2', 'ask_name'),
            $this->edge('menu', 'p3', 'assign'),
            $this->edge('programmes', 'out', 'end'),
            $this->edge('fees', 'out', 'end'),
            $this->edge('ask_name', 'out', 'ask_email'),
            $this->edge('ask_email', 'out', 'deal'),
            $this->edge('deal', 'created', 'thanks'),
            $this->edge('deal', 'error', 'assign'),
            $this->edge('thanks', 'out', 'tpl_class'),
            $this->edge('assign', 'out', 'end'),
            $this->edge('tpl_class', 'out', 'end'),
        ];

        return [
            'name'        => 'Course enquiry (education)',
            'description' => 'Programmes, fees, and enrolment capture. Session replies plus a slot for a Meta-approved class reminder template.',
            'category'    => 'education',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Finance — application / account status (utility). */
    private function loanStatus(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'loan, application, account, status, statement',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'We can share the latest status of your application or account in this chat. We will never ask for your full password or PIN.',
        ]);
        $n[] = $this->node($i++, 'buttons', 'menu', [
            'prompt'  => 'What do you need?',
            'options' => ['Application status', 'Send a document', 'Talk to an advisor'],
            'var'     => 'fin_need',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_ref', [
            'prompt' => 'Reply with your application or account reference (for example LN-2048).',
            'var'    => 'app_ref', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'ack', [
            'text' => "Thanks. We're checking {{app_ref}} and will reply here with the current status.",
        ]);
        $n[] = $this->node($i++, 'message', 'docs', [
            'text' => 'Reply with the document type you need to send (ID, proof of address, or income). A teammate will confirm how to share it securely in this chat.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Finance {{fin_need}} · {{app_ref}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_status', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY loan_status or account_update template for out-of-window updates.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'ask_ref'),
            $this->edge('menu', 'p1', 'docs'),
            $this->edge('menu', 'p2', 'assign'),
            $this->edge('ask_ref', 'out', 'ack'),
            $this->edge('ack', 'out', 'assign'),
            $this->edge('docs', 'out', 'assign'),
            $this->edge('assign', 'out', 'tpl_status'),
            $this->edge('tpl_status', 'out', 'end'),
        ];

        return [
            'name'        => 'Account status (finance)',
            'description' => 'Application and account updates without collecting secrets. Uses session replies and a Meta-approved utility template slot.',
            'category'    => 'finance',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Travel — itinerary / booking changes. */
    private function tripItinerary(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'trip, booking, itinerary, flight, hotel, travel',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'We can share your itinerary or help change dates in this chat.',
        ]);
        $n[] = $this->node($i++, 'buttons', 'menu', [
            'prompt'  => 'What do you need?',
            'options' => ['My itinerary', 'Change dates', 'Talk to travel desk'],
            'var'     => 'travel_need',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_pnr', [
            'prompt' => 'Reply with your booking reference (for example PNR-8821).',
            'var'    => 'booking_ref', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'itinerary', [
            'text' => "Thanks. We're pulling booking {{booking_ref}} and will send the itinerary in this chat.",
        ]);
        $n[] = $this->node($i++, 'message', 'change', [
            'text' => 'Reply with the new dates you need for {{booking_ref}}. A teammate will confirm availability here.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Travel {{travel_need}} · {{booking_ref}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_confirm', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY booking_confirmation or pre_travel_reminder template.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'ask_pnr'),
            $this->edge('menu', 'p1', 'ask_pnr'),
            $this->edge('menu', 'p2', 'assign'),
            $this->edge('ask_pnr', 'out', 'itinerary'),
            $this->edge('itinerary', 'out', 'change'),
            $this->edge('change', 'out', 'assign'),
            $this->edge('assign', 'out', 'tpl_confirm'),
            $this->edge('tpl_confirm', 'out', 'end'),
        ];

        return [
            'name'        => 'Trip itinerary (travel)',
            'description' => 'Booking lookup and date changes. Session chat plus a slot for a Meta-approved travel utility template.',
            'category'    => 'travel',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** SaaS — setup / billing / success handoff. */
    private function saasOnboarding(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'setup, onboard, trial, login, billing, help',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'Hi {{name}} — we can help you get set up or look up a billing question in this chat.',
        ]);
        $n[] = $this->node($i++, 'buttons', 'menu', [
            'prompt'  => 'What do you need?',
            'options' => ['Getting started', 'Billing', 'Talk to success'],
            'var'     => 'saas_need',
        ]);
        $n[] = $this->node($i++, 'message', 'start', [
            'text' => "Getting started:\n1. Invite your team\n2. Connect WhatsApp\n3. Publish your first flow\nReply with the step you're on if you need help.",
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_invoice', [
            'prompt' => 'Reply with your workspace name or invoice number so we can look it up.',
            'var'    => 'ws_ref', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'billing_ack', [
            'text' => 'Thanks. A teammate will confirm the billing details for {{ws_ref}} in this chat.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'SaaS {{saas_need}} · {{ws_ref}}',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'start'),
            $this->edge('menu', 'p1', 'ask_invoice'),
            $this->edge('menu', 'p2', 'assign'),
            $this->edge('start', 'out', 'end'),
            $this->edge('ask_invoice', 'out', 'billing_ack'),
            $this->edge('billing_ack', 'out', 'assign'),
            $this->edge('assign', 'out', 'end'),
        ];

        return [
            'name'        => 'Product onboarding (SaaS)',
            'description' => 'Setup checklist, billing lookup, and success-team handoff for customer-initiated chats.',
            'category'    => 'saas',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Agency — project / quote intake. */
    private function agencyIntake(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'quote, project, brief, work with, proposal',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'Tell us about the project and we will confirm next steps in this chat.',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_company', [
            'prompt' => 'What company or brand is this for?',
            'var'    => 'company', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'buttons', 'budget', [
            'prompt'  => 'Rough budget range?',
            'options' => ['Under 2k', '2k–10k', '10k+'],
            'var'     => 'budget',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_brief', [
            'prompt' => 'In one message, what should we deliver and by when?',
            'var'    => 'brief', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'deal', 'deal', [
            'action' => 'create', 'dealName' => '{{company}} — {{budget}}',
            'stageId' => '', 'value' => '', 'ownerId' => '', 'saveAs' => 'deal_id',
        ]);
        $n[] = $this->node($i++, 'message', 'thanks', [
            'text' => 'Thanks. We have the brief for {{company}} and a teammate will follow up in this chat.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Agency brief · {{company}} · {{budget}} · {{brief}}',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'ask_company'),
            $this->edge('ask_company', 'out', 'budget'),
            $this->edge('budget', 'p0', 'ask_brief'),
            $this->edge('budget', 'p1', 'ask_brief'),
            $this->edge('budget', 'p2', 'ask_brief'),
            $this->edge('ask_brief', 'out', 'deal'),
            $this->edge('deal', 'created', 'thanks'),
            $this->edge('deal', 'error', 'assign'),
            $this->edge('thanks', 'out', 'assign'),
            $this->edge('assign', 'out', 'end'),
        ];

        return [
            'name'        => 'Project intake (agency)',
            'description' => 'Captures company, budget band, and brief, then opens a CRM deal and hands off to a teammate.',
            'category'    => 'agency',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Real estate — viewing request. */
    private function propertyViewing(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'viewing, property, visit, house, apartment, rent, buy',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'We can arrange a property viewing in this chat.',
        ]);
        $n[] = $this->node($i++, 'list', 'type', [
            'prompt'  => 'What are you looking for?',
            'button'  => 'Property type',
            'var'     => 'property_type',
            'options' => [
                ['title' => 'Apartment', 'description' => 'Flat or condo', 'section' => 'Type'],
                ['title' => 'House', 'description' => 'Villa or townhouse', 'section' => 'Type'],
                ['title' => 'Commercial', 'description' => 'Office or retail', 'section' => 'Type'],
                ['title' => 'Talk to an agent', 'description' => 'A teammate will join', 'section' => 'Help'],
            ],
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_area', [
            'prompt' => 'Which area or listing ID should we use?',
            'var'    => 'area', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'book_appointment', 'book', [
            'slotCount' => 5,
            'prompt'    => 'Pick a viewing time:',
            'confirmation' => 'Viewing confirmed for {{slot}} · {{property_type}} in {{area}}.',
            'calendarOverride' => '',
            'collectEmail' => true,
        ]);
        $n[] = $this->node($i++, 'message', 'no_slots', [
            'text' => 'No viewing slots match that time. An agent will propose alternatives in this chat.',
        ]);
        $n[] = $this->node($i++, 'deal', 'deal', [
            'action' => 'create', 'dealName' => '{{area}} — {{property_type}}',
            'stageId' => '', 'value' => '', 'ownerId' => '', 'saveAs' => 'deal_id',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Viewing {{property_type}} · {{area}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_view', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY appointment_reminder template for the viewing reminder.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'type'),
            $this->edge('type', 'p0', 'ask_area'),
            $this->edge('type', 'p1', 'ask_area'),
            $this->edge('type', 'p2', 'ask_area'),
            $this->edge('type', 'p3', 'assign'),
            $this->edge('ask_area', 'out', 'book'),
            $this->edge('book', 'booked', 'deal'),
            $this->edge('book', 'no_slots', 'no_slots'),
            $this->edge('no_slots', 'out', 'assign'),
            $this->edge('deal', 'created', 'tpl_view'),
            $this->edge('deal', 'error', 'assign'),
            $this->edge('assign', 'out', 'end'),
            $this->edge('tpl_view', 'out', 'end'),
        ];

        return [
            'name'        => 'Property viewing (real estate)',
            'description' => 'Property type, area, and viewing slot. Session chat plus a Meta-approved reminder template slot.',
            'category'    => 'realestate',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Beauty & wellness — salon booking. */
    private function salonBooking(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'hair, salon, spa, nail, appointment, book',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'We can book or update a salon appointment in this chat.',
        ]);
        $n[] = $this->node($i++, 'buttons', 'menu', [
            'prompt'  => 'What would you like?',
            'options' => ['Book a service', 'Reschedule', 'Services & prices'],
            'var'     => 'salon_action',
        ]);
        $n[] = $this->node($i++, 'list', 'service', [
            'prompt'  => 'Which service?',
            'button'  => 'Services',
            'var'     => 'service',
            'options' => [
                ['title' => 'Haircut', 'description' => 'Cut and finish', 'section' => 'Hair'],
                ['title' => 'Colour', 'description' => 'Tint or highlights', 'section' => 'Hair'],
                ['title' => 'Spa / nails', 'description' => 'Wellness treatments', 'section' => 'Wellness'],
            ],
        ]);
        $n[] = $this->node($i++, 'book_appointment', 'book', [
            'slotCount' => 6,
            'prompt'    => 'Pick a time:',
            'confirmation' => '{{service}} booked for {{slot}}.',
            'calendarOverride' => '',
            'collectEmail' => false,
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_current', [
            'prompt' => 'Reply with your current appointment date and time so we can move it.',
            'var'    => 'current_slot', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'prices', [
            'text' => 'Update this message with your current price list. A teammate can confirm the exact amount in this chat.',
        ]);
        $n[] = $this->node($i++, 'message', 'no_slots', [
            'text' => 'That time is full. A teammate will offer the next available slot here.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Salon {{salon_action}} · {{service}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_reminder', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY appointment_reminder template.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'service'),
            $this->edge('menu', 'p1', 'ask_current'),
            $this->edge('menu', 'p2', 'prices'),
            $this->edge('service', 'p0', 'book'),
            $this->edge('service', 'p1', 'book'),
            $this->edge('service', 'p2', 'book'),
            $this->edge('book', 'booked', 'tpl_reminder'),
            $this->edge('book', 'no_slots', 'no_slots'),
            $this->edge('no_slots', 'out', 'assign'),
            $this->edge('ask_current', 'out', 'book'),
            $this->edge('prices', 'out', 'end'),
            $this->edge('assign', 'out', 'end'),
            $this->edge('tpl_reminder', 'out', 'end'),
        ];

        return [
            'name'        => 'Salon booking (beauty)',
            'description' => 'Service picker, booking, and reschedule. Session chat plus a Meta-approved appointment reminder slot.',
            'category'    => 'beauty',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Logistics — parcel / delivery tracking. */
    private function parcelTracking(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'delivery, parcel, tracking, courier, shipment',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'We can share the latest delivery status in this chat.',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_awb', [
            'prompt' => 'Reply with your tracking or AWB number.',
            'var'    => 'awb', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'list', 'next', [
            'prompt'  => 'What do you need for {{awb}}?',
            'button'  => 'View options',
            'var'     => 'log_need',
            'options' => [
                ['title' => 'Where is my parcel', 'description' => 'Latest scan status', 'section' => 'Delivery'],
                ['title' => 'Reschedule delivery', 'description' => 'Pick another day', 'section' => 'Delivery'],
                ['title' => 'Talk to dispatch', 'description' => 'A teammate will join', 'section' => 'Help'],
            ],
        ]);
        $n[] = $this->node($i++, 'message', 'where', [
            'text' => "Thanks. We're checking {{awb}} and will reply here with the latest scan.",
        ]);
        $n[] = $this->node($i++, 'message', 'reschedule', [
            'text' => 'Reply with a preferred delivery day for {{awb}} and we will confirm it in this chat.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Logistics {{log_need}} · {{awb}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_ship', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY shipment_update template for out-of-window scans.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'ask_awb'),
            $this->edge('ask_awb', 'out', 'next'),
            $this->edge('next', 'p0', 'where'),
            $this->edge('next', 'p1', 'reschedule'),
            $this->edge('next', 'p2', 'assign'),
            $this->edge('where', 'out', 'assign'),
            $this->edge('reschedule', 'out', 'assign'),
            $this->edge('assign', 'out', 'tpl_ship'),
            $this->edge('tpl_ship', 'out', 'end'),
        ];

        return [
            'name'        => 'Parcel tracking (logistics)',
            'description' => 'AWB lookup, reschedule, and dispatch handoff. Session replies plus a Meta-approved shipment-update template slot.',
            'category'    => 'logistics',
            'graph'       => $this->graph($n, $e),
        ];
    }
}
