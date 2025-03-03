<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = [
            [
                'name'    => 'general',
                'name_ar' => 'عام',
            ],
            [
                'name'    => 'computer science',
                'name_ar' => 'علوم الحاسب',
            ],
            [
                'name'    => 'information technology',
                'name_ar' => 'تكنولوجيا المعلومات',
            ],
            [
                'name'    => 'information systems',
                'name_ar' => 'نظم المعلومات',
            ],
            [
                'name'    => 'artificial intelligence',
                'name_ar' => 'الذكاء الاصطناعي',
            ],
            [
                'name'    => 'cybersecurity',
                'name_ar' => 'الأمن السيبراني',
            ],
            [
                'name'    => 'biomedical',
                'name_ar' => 'الطب الحيوي',
            ],
        ];

        foreach ($departments as $department) {
            Department::create($department);
        }
    }
}
