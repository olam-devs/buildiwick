<?php

namespace Database\Seeders;

use App\Models\Project;
use Illuminate\Database\Seeder;

/**
 * The 16 real active projects, transcribed from the Consolidated tab of
 * Buildiwick_All_Projects_Interim_Tracker_4.xlsx (2026-09-25). Locations left
 * null where the client's own sheet still says "Enter Location" — that's a
 * real gap in their data, not ours to invent.
 */
class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        $projects = [
            ['BWG-OYS-2026-001', 'Oysterbay Residence', 'Oysterbay, Dar es Salaam'],
            ['BWG-MSK-2026-001', 'Masaki Residence Finish', 'Masaki, Dar es Salaam'],
            ['BWG-MBS-2026-001', 'Mbezi Residence by Shab', 'Mbezi, Dar es Salaam'],
            ['BWG-MWD-2026-001', 'Mweni Duplex Residence', null],
            ['BWG-MBD-2026-001', 'Mbezi Airbnb by Doreen', 'Mbezi, Dar es Salaam'],
            ['BWG-KTB-2026-001', 'Kimara Temboni Residence', 'Kimara Temboni, Dar es Salaam'],
            ['BWG-COP-2026-001', 'COPRA', null],
            ['BWG-VKA-2026-001', 'VK Apartments', null],
            ['BWG-KIN-2026-001', 'Kinondoni Residence', 'Kinondoni, Dar es Salaam'],
            ['BWG-OYM-2026-001', 'Oyster Masjeed', 'Oysterbay, Dar es Salaam'],
            ['BWG-MVC-2026-001', 'Mikvah by CHABA', null],
            ['BWG-MSC-2026-001', 'Msasani Commercial Building', 'Msasani, Kinondoni, Dar es Salaam'],
            ['BWG-MKR-2026-001', 'Mikocheni Residence Finish', 'Mikocheni, Dar es Salaam'],
            ['BWG-TFS-2026-001', 'TFS Design', null],
            ['BWG-MBW-2026-001', 'Mbweni Residence by FA', 'Mbweni, Dar es Salaam'],
            ['BWG-KICD-2026-001', 'KICD Outdoor Lounge', 'Dar es Salaam'],
        ];

        foreach ($projects as [$code, $name, $location]) {
            Project::updateOrCreate(
                ['project_code' => $code],
                ['name' => $name, 'status' => 'active', 'location' => $location]
            );
        }
    }
}
