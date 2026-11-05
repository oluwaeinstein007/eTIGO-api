<?php

namespace Database\Seeders;

use App\Enums\FuelType;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class VehicleCatalogSeeder extends Seeder
{
    /**
     * Seed notable vehicle makes and models across all supported fuel types.
     * Uses firstOrCreate so it safely runs on every deploy without duplication.
     */
    public function run(): void
    {
        $catalog = [
            'BYD' => [
                'sort_order' => 1,
                'models' => [
                    ['name' => 'Dolphin', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 1],
                    ['name' => 'Atto 3', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 2],
                    ['name' => 'Seal', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 3],
                    ['name' => 'Tang', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 4],
                    ['name' => 'Han', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 5],
                    ['name' => 'Yuan Plus', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 6],
                ],
            ],
            'Tesla' => [
                'sort_order' => 2,
                'models' => [
                    ['name' => 'Model 3', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 1],
                    ['name' => 'Model Y', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 2],
                    ['name' => 'Model S', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 3],
                    ['name' => 'Model X', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 4],
                ],
            ],
            'Toyota' => [
                'sort_order' => 3,
                'models' => [
                    ['name' => 'Corolla', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 1],
                    ['name' => 'Camry', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 2],
                    ['name' => 'Prius', 'fuel_type' => FuelType::Hybrid->value, 'sort_order' => 3],
                    ['name' => 'Prius Prime', 'fuel_type' => FuelType::PlugInHybrid->value, 'sort_order' => 4],
                    ['name' => 'Corolla Hybrid', 'fuel_type' => FuelType::Hybrid->value, 'sort_order' => 5],
                    ['name' => 'Camry Hybrid', 'fuel_type' => FuelType::Hybrid->value, 'sort_order' => 6],
                    ['name' => 'RAV4', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 7],
                    ['name' => 'RAV4 Hybrid', 'fuel_type' => FuelType::Hybrid->value, 'sort_order' => 8],
                    ['name' => 'RAV4 Prime', 'fuel_type' => FuelType::PlugInHybrid->value, 'sort_order' => 9],
                    ['name' => 'Yaris', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 10],
                    ['name' => 'Corolla CNG', 'fuel_type' => FuelType::Cng->value, 'sort_order' => 11],
                    ['name' => 'Hilux', 'fuel_type' => FuelType::Diesel->value, 'sort_order' => 12],
                    ['name' => 'Land Cruiser Prado', 'fuel_type' => FuelType::Diesel->value, 'sort_order' => 13],
                ],
            ],
            'Honda' => [
                'sort_order' => 4,
                'models' => [
                    ['name' => 'Civic', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 1],
                    ['name' => 'Accord', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 2],
                    ['name' => 'City', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 3],
                    ['name' => 'Accord Hybrid', 'fuel_type' => FuelType::Hybrid->value, 'sort_order' => 4],
                    ['name' => 'CR-V', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 5],
                    ['name' => 'CR-V Hybrid', 'fuel_type' => FuelType::Hybrid->value, 'sort_order' => 6],
                    ['name' => 'Insight', 'fuel_type' => FuelType::Hybrid->value, 'sort_order' => 7],
                ],
            ],
            'Hyundai' => [
                'sort_order' => 5,
                'models' => [
                    ['name' => 'Ioniq 5', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 1],
                    ['name' => 'Ioniq 6', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 2],
                    ['name' => 'Kona Electric', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 3],
                    ['name' => 'Elantra', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 4],
                    ['name' => 'Sonata', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 5],
                    ['name' => 'Accent', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 6],
                    ['name' => 'Sonata Hybrid', 'fuel_type' => FuelType::Hybrid->value, 'sort_order' => 7],
                    ['name' => 'Aura CNG', 'fuel_type' => FuelType::Cng->value, 'sort_order' => 8],
                    ['name' => 'Grand i10 Nios CNG', 'fuel_type' => FuelType::Cng->value, 'sort_order' => 9],
                ],
            ],
            'Kia' => [
                'sort_order' => 6,
                'models' => [
                    ['name' => 'EV6', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 1],
                    ['name' => 'Niro EV', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 2],
                    ['name' => 'Forte', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 3],
                    ['name' => 'Rio', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 4],
                    ['name' => 'Cerato', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 5],
                    ['name' => 'Optima', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 6],
                    ['name' => 'Sportage', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 7],
                ],
            ],
            'Suzuki' => [
                'sort_order' => 7,
                'models' => [
                    ['name' => 'Dzire S-CNG', 'fuel_type' => FuelType::Cng->value, 'sort_order' => 1],
                    ['name' => 'Swift', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 2],
                    ['name' => 'Dzire', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 3],
                    ['name' => 'Baleno', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 4],
                    ['name' => 'Ertiga S-CNG', 'fuel_type' => FuelType::Cng->value, 'sort_order' => 5],
                ],
            ],
            'Lexus' => [
                'sort_order' => 8,
                'models' => [
                    ['name' => 'RX 450h', 'fuel_type' => FuelType::Hybrid->value, 'sort_order' => 1],
                    ['name' => 'ES 300h', 'fuel_type' => FuelType::Hybrid->value, 'sort_order' => 2],
                    ['name' => 'NX 350h', 'fuel_type' => FuelType::Hybrid->value, 'sort_order' => 3],
                    ['name' => 'ES 350', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 4],
                    ['name' => 'RX 350', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 5],
                ],
            ],
            'Mercedes-Benz' => [
                'sort_order' => 9,
                'models' => [
                    ['name' => 'C 300e', 'fuel_type' => FuelType::PlugInHybrid->value, 'sort_order' => 1],
                    ['name' => 'E 300e', 'fuel_type' => FuelType::PlugInHybrid->value, 'sort_order' => 2],
                    ['name' => 'C-Class', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 3],
                    ['name' => 'E-Class', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 4],
                    ['name' => 'CLA-Class', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 5],
                    ['name' => 'EQE', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 6],
                    ['name' => 'EQS', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 7],
                ],
            ],
            'BMW' => [
                'sort_order' => 10,
                'models' => [
                    ['name' => '330e', 'fuel_type' => FuelType::PlugInHybrid->value, 'sort_order' => 1],
                    ['name' => '530e', 'fuel_type' => FuelType::PlugInHybrid->value, 'sort_order' => 2],
                    ['name' => 'i4', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 3],
                    ['name' => 'iX3', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 4],
                    ['name' => '3 Series', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 5],
                    ['name' => '5 Series', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 6],
                ],
            ],
            'Volkswagen' => [
                'sort_order' => 11,
                'models' => [
                    ['name' => 'ID.4', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 1],
                    ['name' => 'Golf', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 2],
                    ['name' => 'Passat', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 3],
                    ['name' => 'Golf TDI', 'fuel_type' => FuelType::Diesel->value, 'sort_order' => 4],
                    ['name' => 'Passat TDI', 'fuel_type' => FuelType::Diesel->value, 'sort_order' => 5],
                    ['name' => 'Jetta', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 6],
                ],
            ],
            'Innoson' => [
                'sort_order' => 12,
                'models' => [
                    ['name' => 'Connect CNG', 'fuel_type' => FuelType::Cng->value, 'sort_order' => 1],
                    ['name' => 'Caris CNG', 'fuel_type' => FuelType::Cng->value, 'sort_order' => 2],
                    ['name' => 'Fox CNG', 'fuel_type' => FuelType::Cng->value, 'sort_order' => 3],
                    ['name' => 'Capser', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 4],
                    ['name' => 'G5T', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 5],
                ],
            ],
            'Nord' => [
                'sort_order' => 13,
                'models' => [
                    ['name' => 'A3 CNG', 'fuel_type' => FuelType::Cng->value, 'sort_order' => 1],
                    ['name' => 'Flit CNG', 'fuel_type' => FuelType::Cng->value, 'sort_order' => 2],
                    ['name' => 'Demir', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 3],
                ],
            ],
            'Bajaj' => [
                'sort_order' => 14,
                'models' => [
                    ['name' => 'Qute CNG', 'fuel_type' => FuelType::Cng->value, 'sort_order' => 1],
                    ['name' => 'Maxima CNG', 'fuel_type' => FuelType::Cng->value, 'sort_order' => 2],
                ],
            ],
            'Nissan' => [
                'sort_order' => 15,
                'models' => [
                    ['name' => 'Leaf', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 1],
                    ['name' => 'Almera', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 2],
                    ['name' => 'Sentra', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 3],
                    ['name' => 'Altima', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 4],
                ],
            ],
            'MG' => [
                'sort_order' => 16,
                'models' => [
                    ['name' => 'MG4 EV', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 1],
                    ['name' => 'ZS EV', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 2],
                    ['name' => 'MG ZS', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 3],
                ],
            ],
            'GAC' => [
                'sort_order' => 17,
                'models' => [
                    ['name' => 'Aion Y', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 1],
                    ['name' => 'Aion S', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 2],
                    ['name' => 'GA4', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 3],
                    ['name' => 'GS3', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 4],
                ],
            ],
            'Peugeot' => [
                'sort_order' => 18,
                'models' => [
                    ['name' => 'e-208', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 1],
                    ['name' => '301', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 2],
                    ['name' => '508 HDI', 'fuel_type' => FuelType::Diesel->value, 'sort_order' => 3],
                    ['name' => '308 HDI', 'fuel_type' => FuelType::Diesel->value, 'sort_order' => 4],
                ],
            ],
            'Ford' => [
                'sort_order' => 19,
                'models' => [
                    ['name' => 'Mustang Mach-E', 'fuel_type' => FuelType::Electric->value, 'sort_order' => 1],
                    ['name' => 'Focus', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 2],
                    ['name' => 'Fusion', 'fuel_type' => FuelType::Petrol->value, 'sort_order' => 3],
                    ['name' => 'Ranger', 'fuel_type' => FuelType::Diesel->value, 'sort_order' => 4],
                ],
            ],
        ];

        foreach ($catalog as $makeName => $makeData) {
            $make = VehicleMake::firstOrCreate(
                ['name' => $makeName],
                [
                    'slug' => Str::slug($makeName),
                    'is_active' => true,
                    'sort_order' => $makeData['sort_order'],
                ]
            );

            foreach ($makeData['models'] as $modelData) {
                VehicleModel::firstOrCreate(
                    [
                        'vehicle_make_id' => $make->id,
                        'name' => $modelData['name'],
                    ],
                    [
                        'fuel_type' => $modelData['fuel_type'],
                        'is_active' => true,
                        'sort_order' => $modelData['sort_order'],
                    ]
                );
            }
        }
    }
}
