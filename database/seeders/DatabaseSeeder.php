<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Document Types
        $documentTypes = [
            ['document_code' => 'AOC', 'document_type' => 'Abstract of Canvass', 'state' => 1],
            ['document_code' => 'AR', 'document_type' => 'AIR/RIS', 'state' => 1],
            ['document_code' => 'BR', 'document_type' => 'BAC Resolution', 'state' => 1],
            ['document_code' => 'COM', 'document_type' => 'Communications', 'state' => 1],
            ['document_code' => 'IPW', 'document_type' => 'Individual Program of Work', 'state' => 1],
            ['document_code' => 'OBR', 'document_type' => 'Obligation Request', 'state' => 1],
            ['document_code' => 'PO', 'document_type' => 'Purchase Order', 'state' => 1],
            ['document_code' => 'PP', 'document_type' => 'Project Proposal', 'state' => 1],
            ['document_code' => 'PR', 'document_type' => 'Purchase Request', 'state' => 1],
            ['document_code' => 'PRMT', 'document_type' => 'Permits', 'state' => 1],
            ['document_code' => 'PY', 'document_type' => 'Payroll', 'state' => 1],
            ['document_code' => 'VC', 'document_type' => 'Disbursement Voucher/Petty Cash', 'state' => 1],
        ];

        DB::table('document_types')->upsert(
            $documentTypes,
            ['document_code'],           // unique key to match on
            ['document_type', 'state']   // columns to update if it already exists
        );

        // Departments
        $departments = [
            ['code' => 'ABEO', 'name' => 'Agricultural and Biosystems Engineering Office', 'state' => 1],
            ['code' => 'ACA', 'name' => 'Office of the City Accountant', 'state' => 1],
            ['code' => 'ADM', 'name' => 'Office of the City Administrator', 'state' => 1],
            ['code' => 'AGR', 'name' => 'Office of the City Agriculturist', 'state' => 1],
            ['code' => 'BAC', 'name' => 'Bids and Awards Committee', 'state' => 1],
            ['code' => 'BFP', 'name' => 'Bureau of Fire', 'state' => 1],
            ['code' => 'CBO', 'name' => 'Office of the City Budget Officer', 'state' => 1],
            ['code' => 'CDO', 'name' => 'Cooperative Development Office', 'state' => 1],
            ['code' => 'CDRRMO', 'name' => 'City Disaster Risk Reduction and Management Office', 'state' => 1],
            ['code' => 'CHO', 'name' => 'Office of the City Health Officer', 'state' => 1],
            ['code' => 'CLO', 'name' => 'Office of the City Legal Officer', 'state' => 1],
            ['code' => 'CMLC', 'name' => 'COMELEC', 'state' => 1],
            ['code' => 'COA', 'name' => 'Commission on Audit', 'state' => 1],
            ['code' => 'CSD', 'name' => 'City Schools Division', 'state' => 1],
            ['code' => 'CTO', 'name' => 'Office of the City Treasurer', 'state' => 1],
            ['code' => 'DILG', 'name' => 'LGOO-DILG', 'state' => 1],
            ['code' => 'EAS', 'name' => 'Office of the City Engineer', 'state' => 1],
            ['code' => 'EEM', 'name' => 'Office of the Economic Enterprises Management Officer', 'state' => 1],
            ['code' => 'ELEC', 'name' => 'Electrical Section', 'state' => 1],
            ['code' => 'ENR', 'name' => 'Office of the City Environment and Natural Resources Officer', 'state' => 1],
            ['code' => 'GAD', 'name' => 'Gender and Development Office', 'state' => 1],
            ['code' => 'GSO', 'name' => 'Office of the General Services Officer', 'state' => 1],
            ['code' => 'HRM', 'name' => 'Office of the Human Resources Officer', 'state' => 1],
            ['code' => 'ICT', 'name' => 'Information Communications Technology', 'state' => 1],
            ['code' => 'IDS', 'name' => 'Information Dissemination Section', 'state' => 1],
            ['code' => 'LEBDO', 'name' => 'Local Economic and Business Development Office', 'state' => 1],
            ['code' => 'LIB', 'name' => 'City Library', 'state' => 1],
            ['code' => 'LNMB', 'name' => 'Liga ng mga Barangay', 'state' => 1],
            ['code' => 'LUSCM', 'name' => 'La Union Science Centrum and Museum', 'state' => 1],
            ['code' => 'OCA', 'name' => 'Office of the City Assessor', 'state' => 1],
            ['code' => 'OCM', 'name' => 'Office of the City Mayor', 'state' => 1],
            ['code' => 'OCVM', 'name' => 'Office of the City Vice Mayor', 'state' => 1],
            ['code' => 'OIA', 'name' => 'Office of the Internal Auditor', 'state' => 1],
            ['code' => 'OPS', 'name' => 'Office for Public Safety', 'state' => 1],
            ['code' => 'OSM', 'name' => 'Office for Strategy Management', 'state' => 1],
            ['code' => 'OSP', 'name' => 'Sanggunian Panlungsod', 'state' => 1],
            ['code' => 'OSSP', 'name' => 'Office of the Secretary to the Sanggunian Panlungsod', 'state' => 1],
            ['code' => 'PACU', 'name' => 'Public Assistance and Complaints Unit', 'state' => 1],
            ['code' => 'PDAO', 'name' => 'Person with Disabilities Affairs Office', 'state' => 1],
            ['code' => 'PDO', 'name' => 'Office of the City Planning and Development Coordinator', 'state' => 1],
            ['code' => 'PESO', 'name' => 'Public Employment Service Office', 'state' => 1],
            ['code' => 'PNP', 'name' => 'City Police Office', 'state' => 1],
            ['code' => 'REC', 'name' => 'Records Office', 'state' => 1],
            ['code' => 'REG', 'name' => 'Office of the Civil Registrar', 'state' => 1],
            ['code' => 'SNC', 'name' => 'Senior Citizen', 'state' => 1],
            ['code' => 'SWD', 'name' => "Office of the City Social Welfare and Development", "state" => 1],
            ['code' => "VET", "name" => "Office of the City Veterinarian", "state" => 1],
            ['code' => "YDO", "name" => "Youth Development Office", "state" => 1],
        ];

        foreach ($departments as $data) {
            DB::table('departments')->updateOrInsert(
                ['code' => $data['code']],
                $data
            );
        }

        // Users (Department IDs kept null or specific if you re-add departments later)
        $users = [
            [
                'name' => 'System Admin',
                'email' => 'sysadm@gmail.com',
                'role' => 'system_administrator',
                'department_id' => null,
                'state' => 1,
                'password' => bcrypt('password'), // Default password for seeding
            ],
            [
                'name' => 'Records Manager',
                'email' => 'rm@gmail.com',
                'role' => 'records_manager',
                'department_id' => null,
                'state' => 1,
                'password' => bcrypt('password'), // Default password for seeding
            ],
        ];

        foreach ($users as $data) {
            User::query()->updateOrCreate(
                ['email' => $data['email']],
                $data
            );
        }

        // Delivery Methods

        $deliveryMethods = [

            ['delivery_method_name' => 'Email', 'state' => 1],
            ['delivery_method_name' => 'Fax', 'state' => 1],
            ['delivery_method_name' => 'Hand Carry', 'state' => 1],
            ['delivery_method_name' => 'Post Mail', 'state' => 1],
        ];

        foreach ($deliveryMethods as $data) {
            DB::table('delivery_methods')->updateOrInsert(
                ['delivery_method_name' => $data['delivery_method_name']],
                $data
            );
        }

        // Transaction Types

        $transactionTypes = [
            
            ['name' => 'Simple Transaction', 'processing_days' => 3, 'state' => 1],
            ['name' => 'Complex Transaction', 'processing_days' => 7, 'state' => 1],
            ['name' => 'Highly Technical Transaction', 'processing_days' => 20, 'state' => 1],
        ];

        foreach ($transactionTypes as $data) {
            DB::table('transaction_types')->updateOrInsert(
                ['name' => $data['name']],
                $data
            );
        }

        // Document Classifications
    
        $documentClassifications = [
            ['name' => 'AICS', 'description' => null, 'state' => 1],
            ['name' => 'Barangay/SK Resolution', 'description' => null, 'state' => 1],
            ['name' => 'Capacity Development', 'description' => null, 'state' => 1],
            ['name' => 'Complaint', 'description' => null, 'state' => 1],
            ['name' => 'Financial', 'description' => null, 'state' => 1],
            ['name' => 'For Signature', 'description' => null, 'state' => 1],
            ['name' => 'Human Resource', 'description' => null, 'state' => 1],
            ['name' => 'Invitation', 'description' => null, 'state' => 1],
            ['name' => 'Issuances', 'description' => null, 'state' => 1],
            ['name' => 'Legal Document', 'description' => null, 'state' => 1],
            ['name' => 'Legislative Document', 'description' => null, 'state' => 1],
            ['name' => 'Project Proposal', 'description' => null, 'state' => 1],
            ['name' => 'Report', 'description' => null, 'state' => 1],
            ['name' => 'Request', 'description' => null, 'state' => 1],
            ['name' => 'Request for City Govt Properties', 'description' => null, 'state' => 1],
            ['name' => 'Request for Data', 'description' => null, 'state' => 1],
            ['name' => 'Request for OT', 'description' => null, 'state' => 1],
            ['name' => 'Special Mayors Permit', 'description' => null, 'state' => 1],
        ];

        foreach ($documentClassifications as $data) {
            DB::table('document_classifications')->updateOrInsert(
                ['name' => $data['name']],
                $data
            );
        }
    }
}