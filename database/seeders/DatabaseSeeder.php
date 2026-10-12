<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Lead;
use App\Models\Call;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            StorageProviderCatalogSeeder::class,
            StoragePlanSeeder::class,
            ManagedStorageProfileSeeder::class,
        ]);

        // Create admin
        $admin = User::create([
            'name'     => 'Admin User',
            'email'    => 'admin@crm.com',
            'password' => Hash::make('password'),
            'role'     => 'admin',
        ]);

        // Create agents
        $agent1 = User::create([
            'name'     => 'John Smith',
            'email'    => 'agent@crm.com',
            'password' => Hash::make('password'),
            'role'     => 'agent',
        ]);

        $agent2 = User::create([
            'name'     => 'Sarah Johnson',
            'email'    => 'sarah@crm.com',
            'password' => Hash::make('password'),
            'role'     => 'agent',
        ]);

        // Create sample leads
        $leads = [
            ['first_name'=>'Michael','last_name'=>'Brown','email'=>'michael.brown@email.com','phone'=>'(555) 234-5678','phone_2'=>'(555) 234-9999','city'=>'Austin','state'=>'TX','zip'=>'78701','source'=>'Web','status'=>'new','assigned_to'=>$agent1->id],
            ['first_name'=>'Jennifer','last_name'=>'Davis','email'=>'jennifer.d@gmail.com','phone'=>'(555) 345-6789','city'=>'Houston','state'=>'TX','zip'=>'77002','source'=>'Referral','status'=>'contacted','assigned_to'=>$agent1->id],
            ['first_name'=>'Robert','last_name'=>'Wilson','email'=>'rwilson@company.com','phone'=>'(555) 456-7890','city'=>'Dallas','state'=>'TX','zip'=>'75201','source'=>'Phone','status'=>'qualified','assigned_to'=>$agent2->id, 'value'=>15000],
            ['first_name'=>'Emily','last_name'=>'Martinez','email'=>'emily.m@email.com','phone'=>'(555) 567-8901','city'=>'San Antonio','state'=>'TX','zip'=>'78201','source'=>'Web','status'=>'closed_won','assigned_to'=>$agent1->id,'value'=>8500],
            ['first_name'=>'David','last_name'=>'Anderson','email'=>'david.a@email.com','phone'=>'(555) 678-9012','city'=>'Phoenix','state'=>'AZ','zip'=>'85001','source'=>'Referral','status'=>'new','assigned_to'=>$agent2->id],
            ['first_name'=>'Lisa','last_name'=>'Thompson','email'=>'lisa.t@email.com','phone'=>'(555) 789-0123','city'=>'Denver','state'=>'CO','zip'=>'80201','source'=>'Web','status'=>'contacted','assigned_to'=>$agent1->id],
            ['first_name'=>'James','last_name'=>'Garcia','email'=>'james.g@email.com','phone'=>'(555) 890-1234','city'=>'Miami','state'=>'FL','zip'=>'33101','source'=>'Phone','status'=>'negotiation','assigned_to'=>$agent2->id,'value'=>22000],
            ['first_name'=>'Patricia','last_name'=>'Lee','email'=>'patricia.l@email.com','phone'=>'(555) 901-2345','city'=>'Seattle','state'=>'WA','zip'=>'98101','source'=>'Web','status'=>'proposal','assigned_to'=>$agent1->id,'value'=>12000],
        ];

        foreach ($leads as $leadData) {
            $lead = Lead::create($leadData + ['notes' => 'This is a demo lead with sample data.']);
        }

        // Create some calls for demo
        $allLeads = Lead::all();
        foreach ($allLeads->take(6) as $lead) {
            Call::create([
                'lead_id'     => $lead->id,
                'agent_id'    => $lead->assigned_to,
                'disposition' => 'no_answer',
                'called_at'   => now()->subDays(rand(1,5))->subHours(rand(0,8)),
                'duration'    => rand(0, 30),
                'notes'       => 'Initial contact attempt.',
            ]);
        }

        // Create callback scheduled calls
        $callbackLeads = $allLeads->take(3);
        foreach ($callbackLeads as $lead) {
            Call::create([
                'lead_id'      => $lead->id,
                'agent_id'     => $lead->assigned_to,
                'disposition'  => 'callback',
                'called_at'    => now()->subDay(),
                'scheduled_at' => now()->addDays(rand(1,3))->setHour(rand(9,17))->setMinute(0),
                'is_scheduled' => true,
                'completed'    => false,
                'notes'        => 'Customer requested a callback.',
                'duration'     => rand(60, 180),
            ]);
        }

        // Create customer follow-up
        Call::create([
            'lead_id'      => $allLeads->last()->id,
            'agent_id'     => $agent1->id,
            'disposition'  => 'customer',
            'called_at'    => now()->subHours(2),
            'scheduled_at' => now()->addDay()->setHour(10)->setMinute(0),
            'is_scheduled' => true,
            'completed'    => false,
            'notes'        => 'Customer confirmed — schedule onboarding call.',
            'duration'     => 320,
        ]);
    }
}
