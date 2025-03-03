<?php

namespace Database\Seeders;

use App\Models\AcademicDegree;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AcademicDegreeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $degrees = [
            [
                'name'      => 'professor',
                'name_ar'   => 'أستاذ',
                'prefix' => 'Prof.',
                'prefix_ar' => 'أ. د.'
            ],
            [
                'name'      => 'associate professor',
                'name_ar'   => 'أستاذ مساعد',
                'prefix'    => 'Dr.',
                'prefix_ar' => 'د.'
            ],
            [
                'name'      => 'assistant professor',
                'name_ar'   => 'مدرس',
                'prefix'    => 'Dr.',
                'prefix_ar' => 'د.'
            ],
            [
                'name'      => 'assistant lecturer',
                'name_ar'   => 'مدرس مساعد',
                'prefix'    => 'Eng.',
                'prefix_ar' => 'م.'
            ],
            [
                'name'      => 'teaching assistant',
                'name_ar'   => 'معيد',
                'prefix'    => 'Eng.',
                'prefix_ar' => 'م.'
            ],
        ];

        foreach ($degrees as $degree) {
            AcademicDegree::create($degree);
        }
    }
}
