<?php

namespace Database\Seeders;

use App\Models\Contact;
use Illuminate\Database\Seeder;

class ContactSeeder extends Seeder
{
    public function run(): void
    {
        $contacts = [
            ['first_name' => 'Hamisi',   'last_name' => 'Salim',    'job_title' => 'Chief Medical Officer', 'department' => 'Administration', 'email' => 'h.salim@mnh.go.tz',       'phone' => '+255 22 215 0610', 'hospital_id' => 1, 'tags' => ['decision-maker', 'key-contact']],
            ['first_name' => 'Rehema',   'last_name' => 'Ally',     'job_title' => 'Head Nurse',            'department' => 'Nursing',        'email' => 'r.ally@agakhan.or.tz',    'phone' => '+255 22 211 4096', 'hospital_id' => 2, 'tags' => ['procurement', 'key-contact']],
            ['first_name' => 'Angela',   'last_name' => 'Mrema',    'job_title' => 'Biomedical Engineer',   'department' => 'Technical',      'email' => 'a.mrema@kcmc.ac.tz',      'phone' => '+255 27 275 4377', 'hospital_id' => 3, 'tags' => ['technical', 'key-contact']],
            ['first_name' => 'Francis',  'last_name' => 'Magesa',   'job_title' => 'Procurement Officer',   'department' => 'Procurement',    'email' => 'f.magesa@bugando.go.tz',  'phone' => '+255 28 250 0611', 'hospital_id' => 4, 'tags' => ['procurement']],
            ['first_name' => 'Consolata','last_name' => 'Mwita',    'job_title' => 'Hospital Director',     'department' => 'Administration', 'email' => 'c.mwita@dodoma.go.tz',    'phone' => '+255 26 232 1180', 'hospital_id' => 5, 'tags' => ['decision-maker']],
        ];

        foreach ($contacts as $c) {
            $tags = $c['tags'];
            unset($c['tags']);
            $contact = Contact::create($c);
            foreach ($tags as $tag) {
                $contact->tags()->create(['tag' => $tag]);
            }
            $contact->interactions()->create([
                'type'    => 'call',
                'summary' => 'Initial follow-up call regarding equipment maintenance contract.',
                'outcome' => 'Positive. Interested in annual service plan.',
                'next_action' => 'Send proposal document',
                'next_action_date' => now()->addDays(7)->toDateString(),
            ]);
        }
    }
}
