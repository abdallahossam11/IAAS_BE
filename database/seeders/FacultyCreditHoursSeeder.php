<?php

namespace Database\Seeders;

use App\Models\Faculty;
use Illuminate\Database\Seeder;

/**
 * Upserts the 42 official academic programs (sector, field, name, credit hours)
 * sourced from CH.pdf.
 *
 * Behavior:
 *   - Matches an existing faculties row by its exact program name.
 *   - If found, updates sector/field/credit_hours (does not duplicate).
 *   - If not found, creates the row.
 *   - Never deletes existing custom/manual faculties.
 *   - Does not touch students.
 *
 * Idempotent — safe to run repeatedly (uses updateOrCreate on the unique name).
 */
class FacultyCreditHoursSeeder extends Seeder
{
    /**
     * [sector, field, program name, credit_hours]
     */
    public const PROGRAMS = [
        // Healthcare Sector
        ['Healthcare Sector', 'Medicine', 'Medicine & Surgery Program', 211],
        ['Healthcare Sector', 'Dentistry', 'Dentistry Program', 185],
        ['Healthcare Sector', 'Pharmacy', 'Doctor of Pharmacy (PharmD) Clinical Pharmacy Program', 221],
        ['Healthcare Sector', 'Physical Therapy', 'Physical Therapy Program', 200],
        ['Healthcare Sector', 'Nursing', 'Nursing Program', 145],

        // Sciences Sector — Basic Sciences
        ['Sciences Sector', 'Basic Sciences', 'Molecular Biotechnology Program', 132],
        ['Sciences Sector', 'Basic Sciences', 'Nanoscience & Technology Program (Physics Track)', 132],
        ['Sciences Sector', 'Basic Sciences', 'Nanoscience & Technology Program (Chemistry Track)', 132],
        ['Sciences Sector', 'Basic Sciences', 'Petroleum & Mining Geology Program', 132],

        // Sciences Sector — Computer Science
        ['Sciences Sector', 'Computer Science', 'Artificial Intelligence Science Program', 127],
        ['Sciences Sector', 'Computer Science', 'Biomedical Informatics Program', 127],
        ['Sciences Sector', 'Computer Science', 'Computer Science Program', 127],
        ['Sciences Sector', 'Computer Science', 'Information Technology Program (Dual Degree)', 127],
        ['Sciences Sector', 'Computer Science', 'Graphic Information Technology Program (Dual Degree)', 127],
        ['Sciences Sector', 'Computer Science', 'Software Engineering Program (Dual Degree)', 127],

        // Sciences Sector — Applied Health Sciences Technology
        ['Sciences Sector', 'Applied Health Sciences Technology', 'Technology of Prosthetic Dentistry Program', 130],
        ['Sciences Sector', 'Applied Health Sciences Technology', 'Technology of Prosthetic & Orthotic Devices Program', 130],
        ['Sciences Sector', 'Applied Health Sciences Technology', 'Technology of Radiology & Medical Imaging Program', 130],
        ['Sciences Sector', 'Applied Health Sciences Technology', 'Technology of Medical Laboratory Program', 130],

        // Engineering Sector — Computer Engineering
        ['Engineering Sector', 'Computer Engineering', 'Engineering Artificial Intelligence Program', 165],
        ['Engineering Sector', 'Computer Engineering', 'Computer Engineering Program', 165],

        // Engineering Sector — Engineering
        ['Engineering Sector', 'Engineering', 'Construction Engineering & Specialized Construction Program', 165],
        ['Engineering Sector', 'Engineering', 'Material & Manufacturing Engineering Program', 165],
        ['Engineering Sector', 'Engineering', 'Mechatronics & Industrial Automation Program', 165],
        ['Engineering Sector', 'Engineering', 'Electrical Engineering Program (Dual Degree)', 165],
        ['Engineering Sector', 'Engineering', 'Architectural Design & Digital Architecture Program', 165],
        ['Engineering Sector', 'Engineering', 'Environmental Architecture & Building Technology Program', 165],

        // Humanities Sector — Media Production
        ['Humanities Sector', 'Media Production', 'Television Production Program', 127],
        ['Humanities Sector', 'Media Production', 'Advertising Production Program', 127],

        // Humanities Sector — Administrative Sciences
        ['Humanities Sector', 'Administrative Sciences', 'Economics & Political Sciences Program', 128],
        ['Humanities Sector', 'Administrative Sciences', 'Business Information Systems Program', 128],
        ['Humanities Sector', 'Administrative Sciences', 'Logistics & Supply Chain Management Program', 128],
        ['Humanities Sector', 'Administrative Sciences', 'Business Administration Program (Dual Degree)', 128],
        ['Humanities Sector', 'Administrative Sciences', 'Sports Business Program (Dual Degree)', 128],
        ['Humanities Sector', 'Administrative Sciences', 'Computer Information Systems Program (Dual Degree)', 128],
        ['Humanities Sector', 'Administrative Sciences', 'Marketing Program (Dual Degree)', 128],

        // Creative Arts Sector — Art and Design
        ['Creative Arts Sector', 'Art and Design', 'Graphic Design Program', 144],
        ['Creative Arts Sector', 'Art and Design', 'Animation Movies Program', 144],
        ['Creative Arts Sector', 'Art and Design', 'Textile & Apparel Design Program', 144],
        ['Creative Arts Sector', 'Art and Design', 'Interior Architecture Program', 144],
        ['Creative Arts Sector', 'Art and Design', 'Visual Art Program', 144],
        ['Creative Arts Sector', 'Art and Design', 'Music Program', 144],
    ];

    public function run(): void
    {
        foreach (self::PROGRAMS as [$sector, $field, $name, $creditHours]) {
            Faculty::updateOrCreate(
                ['name' => $name],
                [
                    'sector' => $sector,
                    'field' => $field,
                    'credit_hours' => $creditHours,
                ],
            );
        }
    }
}
