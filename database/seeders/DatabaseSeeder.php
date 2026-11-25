<?php

namespace Database\Seeders;

use App\Models\Approval;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\OpportunityDocument;
use App\Models\OwnershipInterest;
use App\Models\Participation;
use App\Models\PlatformConfig;
use App\Models\Refund;
use App\Models\SupplierPayout;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\LedgerService;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\References;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    private LedgerService $ledger;

    /** @var array<int, int> */
    private array $participantIds = [];

    private User $demo;

    private User $admin;

    private User $ops;

    private User $finance;

    private string $passwordHash;

    public function run(): void
    {
        $this->ledger = app(LedgerService::class);
        $this->ledger->seedChart();

        $this->passwordHash = Hash::make(AdminUserSeeder::PASSWORD);

        $this->call(AdminUserSeeder::class);
        $this->admin = User::where('email', AdminUserSeeder::ADMIN_EMAIL)->firstOrFail();
        $this->demo = User::where('email', AdminUserSeeder::PARTICIPANT_EMAIL)->firstOrFail();

        $this->seedUsers();
        $this->seedPlatformConfig();

        [$rice, $riceHolders] = $this->seedRice();
        [$farmland, $farmlandHolders] = $this->seedFarmland();
        $this->seedProperty();

        $this->seedSecondaryOpportunities();
        $this->seedAcquiringOpportunity();
        $this->seedCompletedAsset();
        $this->seedRefund($rice, $riceHolders);
        $this->seedNotifications();
        $this->seedWaitlist();

        AuditLog::record($this->admin, 'platform.seeded', 'platform', 1, 'Demo dataset created');
    }

    private function seedUsers(): void
    {
        $this->ops = User::create([
            'name' => 'Ngozi Eze',
            'email' => 'ops@shareup.ng',
            'phone' => '+234 801 000 0002',
            'password' => 'ShareUp2026!',
            'role' => 'OPS',
            'status' => 'ACTIVE',
            'kyc_status' => 'VERIFIED',
            'email_verified_at' => now(),
        ]);

        $this->finance = User::create([
            'name' => 'Ifeanyi Balogun',
            'email' => 'finance@shareup.ng',
            'phone' => '+234 801 000 0003',
            'password' => 'ShareUp2026!',
            'role' => 'FINANCE',
            'status' => 'ACTIVE',
            'kyc_status' => 'VERIFIED',
            'email_verified_at' => now(),
        ]);

        $firstNames = ['Chinedu', 'Aisha', 'Emeka', 'Bisi', 'Fatima', 'Tobi', 'Amaka', 'Ibrahim', 'Funke', 'Kelechi', 'Zainab', 'Segun', 'Nneka', 'Musa', 'Temitope', 'Chioma', 'Yusuf', 'Damilola', 'Halima', 'Obinna', 'Abiola', 'Sadiq', 'Ifeoma', 'Femi', 'Grace', 'Peter', 'Blessing', 'Hassan', 'Joy', 'Daniel'];
        $lastNames = ['Okafor', 'Bello', 'Adeyemi', 'Eze', 'Abubakar', 'Ogunleye', 'Nwosu', 'Ibrahim', 'Adebayo', 'Chukwu', 'Sani', 'Ojo', 'Obi', 'Mohammed', 'Akinwale', 'Uche', 'Lawal', 'Oyelaran', 'Danjuma', 'Onyeka'];

        $rows = [];
        for ($i = 1; $i <= 415; $i++) {
            $name = $firstNames[($i - 1) % count($firstNames)].' '.$lastNames[intdiv($i - 1, count($firstNames)) % count($lastNames)];
            $rows[] = [
                'name' => $name,
                'email' => 'participant'.$i.'@shareup.ng',
                'phone' => '+234 80'.str_pad((string) (20000000 + $i), 8, '0', STR_PAD_LEFT),
                'password' => $this->passwordHash,
                'role' => 'PARTICIPANT',
                'status' => 'ACTIVE',
                'kyc_status' => $i % 7 === 0 ? 'UNVERIFIED' : 'VERIFIED',
                'wallet_balance' => ($i % 11) * 1250000,
                'email_verified_at' => now(),
                'created_at' => now()->subDays(120 - ($i % 90)),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        $this->participantIds = DB::table('users')
            ->where('role', 'PARTICIPANT')
            ->where('email', '!=', 'demo@shareup.ng')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function seedPlatformConfig(): void
    {
        $configs = [
            ['platform_fee_bps', '350', 'Default success fee in basis points'],
            ['policy_version', '3', 'Current funding policy version'],
            ['min_wallet_deposit', '10000', 'Minimum wallet top-up in kobo'],
            ['refund_review_sla_hours', '48', 'Target hours to decide a refund'],
            ['maker_checker', 'enabled', 'Two-person approval for money movements'],
        ];

        foreach ($configs as [$key, $value, $description]) {
            PlatformConfig::create([
                'key' => $key,
                'value' => $value,
                'description' => $description,
                'version' => 1,
                'updated_by_id' => $this->admin->id,
            ]);
        }
    }

    /** @return array{0: Opportunity, 1: array<int, Participation>} */
    private function seedRice(): array
    {
        $opportunity = Opportunity::create([
            'slug' => 'bulk-rice-purchase',
            'title' => 'Bulk Rice Purchase',
            'summary' => 'Buy premium long-grain rice directly from Kebbi millers at wholesale prices.',
            'description' => "We aggregate orders from verified households and food vendors, then buy directly from a vetted miller in Kebbi. Buying at 100-bag volume cuts out two layers of middlemen and drops your cost per bag by up to 23%.\n\nEvery bag is weighed, tagged and stored in a bonded warehouse in Lagos before collection. You own the exact number of bags your contribution buys — not a fund unit, not a promise.",
            'cover_image' => '/images/cover-rice-bags.webp',
            'category' => 'Agriculture',
            'model' => 'COLLECTIVE_PURCHASE',
            'participation_method' => 'UNITS',
            'status' => 'FUNDING',
            'creator_id' => $this->admin->id,
            'creator_source' => 'PLATFORM',
            'supplier_name' => 'Kebbi Rice Millers Cooperative',
            'supplier_contact' => 'sales@kebbirice.example',
            'supplier_verified' => true,
            'target_amount' => 800_000_000,
            'committed_amount' => 576_000_000,
            'target_quantity' => 100,
            'quantity_label' => 'bags',
            'unit_price' => 1_000_000,
            'total_units' => 800,
            'units_committed' => 576,
            'units_per_package' => 8,
            'package_label' => 'bag',
            'min_contribution' => 1_000_000,
            'max_contribution' => 100_000_000,
            'min_units' => 1,
            'max_units' => 100,
            'max_participants' => null,
            'participant_count' => 243,
            'funding_deadline' => now()->addDays(16),
            'expected_acquisition_date' => now()->addDays(22),
            'estimated_delivery_date' => now()->addDays(30),
            'location' => 'Lagos, Nigeria',
            'fee_bps' => 350,
            'policy_version' => 3,
            'policy_terms' => json_encode([
                'feeBps' => 350,
                'fundingPolicy' => 'ALL_OR_NOTHING',
                'refundPolicy' => 'FULL_BEFORE_ACQUISITION',
                'version' => 3,
            ]),
            'allows_partial_funding' => true,
            'allows_overfunding' => false,
            'allow_cancellation' => true,
            'allows_increases' => true,
            'reservation_minutes' => 30,
            'funding_policy' => 'PARTIAL_ALLOWED',
            'refund_policy' => 'FULL_BEFORE_ACQUISITION',
            'risk_notes' => 'Commodity prices can move before purchase. If the supplier price rises above the locked quote, the order is cancelled and every contribution is refunded in full.',
            'acquisition_conditions' => 'Funds are released only after the miller confirms stock, quality inspection passes, and warehouse intake is signed off by two ShareUp operations staff.',
            'legal_structure' => 'CONTRACTUAL_ECONOMIC',
            'regulatory_status' => 'NOT_REQUIRED',
            'regulatory_notes' => 'Physical goods purchase, not a security offering.',
            'ownership_type' => 'PHYSICAL_QUANTITY',
            'verified_at' => now()->subDays(31),
            'published_at' => now()->subDays(30),
        ]);

        $holders = array_slice($this->participantIds, 0, 242);
        array_unshift($holders, $this->demo->id);
        $units = $this->assignUnits($holders, 576, [$this->demo->id => 10]);

        $participations = $this->createParticipations($opportunity, $units, 1_000_000, 28);

        OpportunityDocument::create([
            'opportunity_id' => $opportunity->id,
            'title' => 'Miller supply agreement',
            'kind' => 'SUPPLIER_QUOTE',
            'file_url' => '/documents/kebbi-supply-agreement.pdf',
            'verified' => true,
            'uploaded_at' => now()->subDays(32),
        ]);

        OpportunityDocument::create([
            'opportunity_id' => $opportunity->id,
            'title' => 'Warehouse intake certificate',
            'kind' => 'PROOF_OF_OWNERSHIP',
            'file_url' => '/documents/lagos-warehouse-intake.pdf',
            'verified' => true,
            'uploaded_at' => now()->subDays(29),
        ]);

        return [$opportunity, $participations];
    }

    /** @return array{0: Opportunity, 1: array<int, Participation>} */
    private function seedFarmland(): array
    {
        $opportunity = Opportunity::create([
            'slug' => 'farmland-cooperative',
            'title' => 'Farmland Cooperative',
            'summary' => 'Co-own five acres of irrigated farmland in Ogun State with a managed leaseback.',
            'description' => "A five-acre irrigated parcel 40 minutes from Abeokuta, already planted with cassava and maize. ShareUp handles the title search, survey and lease with the farming cooperative.\n\nOwners receive a registered deed of co-ownership plus a share of harvest proceeds each season. The cooperative farms the land under a five-year lease, so there is nothing for you to manage.",
            'cover_image' => '/images/cover-farmland.webp',
            'category' => 'Real Estate',
            'model' => 'MANAGED_ASSET',
            'participation_method' => 'UNITS',
            'status' => 'FUNDING',
            'creator_id' => $this->admin->id,
            'creator_source' => 'PLATFORM',
            'supplier_name' => 'Ogun Farmland Cooperative',
            'supplier_contact' => 'lands@oguncoop.example',
            'supplier_verified' => true,
            'target_amount' => 1_500_000_000,
            'committed_amount' => 675_000_000,
            'target_quantity' => 5,
            'quantity_label' => 'acres',
            'unit_price' => 2_500_000,
            'total_units' => 600,
            'units_committed' => 270,
            'units_per_package' => 120,
            'package_label' => 'acre',
            'min_contribution' => 2_500_000,
            'max_contribution' => 150_000_000,
            'min_units' => 1,
            'max_units' => 60,
            'participant_count' => 162,
            'funding_deadline' => now()->addDays(24),
            'expected_acquisition_date' => now()->addDays(35),
            'estimated_delivery_date' => null,
            'location' => 'Ogun State, Nigeria',
            'fee_bps' => 350,
            'policy_version' => 3,
            'policy_terms' => json_encode([
                'feeBps' => 350,
                'fundingPolicy' => 'PARTIAL_ALLOWED',
                'refundPolicy' => 'FULL_BEFORE_ACQUISITION',
                'version' => 3,
            ]),
            'allows_partial_funding' => true,
            'allows_overfunding' => false,
            'allow_cancellation' => true,
            'reservation_minutes' => 30,
            'funding_policy' => 'PARTIAL_ALLOWED',
            'refund_policy' => 'FULL_BEFORE_ACQUISITION',
            'risk_notes' => 'Land values can fall and harvests can underperform. Title perfection takes up to 90 days after acquisition; ownership is registered only when perfection completes.',
            'acquisition_conditions' => 'Completion requires a clean title search, a registered survey, and governor’s consent. If any condition fails, contributions are refunded.',
            'legal_structure' => 'BENEFICIAL',
            'regulatory_status' => 'NOT_REQUIRED',
            'regulatory_notes' => 'Co-ownership of land is documented through a registered deed.',
            'ownership_type' => 'BENEFICIAL',
            'verified_at' => now()->subDays(27),
            'published_at' => now()->subDays(25),
        ]);

        $holders = array_slice($this->participantIds, 0, 161);
        array_unshift($holders, $this->demo->id);
        $units = $this->assignUnits($holders, 270, [$this->demo->id => 2]);

        $participations = $this->createParticipations($opportunity, $units, 2_500_000, 25);

        OpportunityDocument::create([
            'opportunity_id' => $opportunity->id,
            'title' => 'Registered survey plan',
            'kind' => 'LEGAL',
            'file_url' => '/documents/ogun-survey-plan.pdf',
            'verified' => true,
            'uploaded_at' => now()->subDays(26),
        ]);

        return [$opportunity, $participations];
    }

    private function seedProperty(): void
    {
        $opportunity = Opportunity::create([
            'slug' => 'residential-property',
            'title' => 'Residential Property',
            'summary' => 'Fractional ownership of a serviced two-bedroom apartment in Lekki Phase 1.',
            'description' => "A fully titled two-bedroom apartment in Lekki Phase 1, acquired at a negotiated price and placed under a professional facility manager. Owners earn rental income pro-rata and can exit through the ShareUp secondary market once it opens.\n\nThis is direct co-ownership: your name sits on the title deed alongside other owners, through a registered co-ownership structure.",
            'cover_image' => '/images/cover-property.webp',
            'category' => 'Real Estate',
            'model' => 'MANAGED_ASSET',
            'participation_method' => 'UNITS',
            'status' => 'FUNDING',
            'creator_id' => $this->admin->id,
            'creator_source' => 'PLATFORM',
            'supplier_name' => 'Lekki Homes Ltd',
            'supplier_contact' => 'deals@lekkihomes.example',
            'supplier_verified' => true,
            'target_amount' => 1_200_000_000,
            'committed_amount' => 816_000_000,
            'target_quantity' => 600,
            'quantity_label' => 'ownership units',
            'unit_price' => 2_000_000,
            'total_units' => 600,
            'units_committed' => 408,
            'units_per_package' => 1,
            'package_label' => 'unit',
            'min_contribution' => 2_000_000,
            'max_contribution' => 120_000_000,
            'min_units' => 1,
            'max_units' => 60,
            'participant_count' => 120,
            'funding_deadline' => now()->addDays(9),
            'expected_acquisition_date' => now()->addDays(21),
            'location' => 'Lekki Phase 1, Lagos',
            'fee_bps' => 350,
            'policy_version' => 3,
            'policy_terms' => json_encode([
                'feeBps' => 350,
                'fundingPolicy' => 'PARTIAL_ALLOWED',
                'refundPolicy' => 'FULL_BEFORE_ACQUISITION',
                'version' => 3,
            ]),
            'allows_partial_funding' => true,
            'allows_overfunding' => false,
            'allow_cancellation' => true,
            'reservation_minutes' => 30,
            'funding_policy' => 'PARTIAL_ALLOWED',
            'refund_policy' => 'FULL_BEFORE_ACQUISITION',
            'risk_notes' => 'Property prices and rental yields can fall. A sale can take time; exit is not guaranteed within a fixed period.',
            'acquisition_conditions' => 'Purchase completes after a clean title search, valuation report, and governor’s consent. Deposits are held in a safeguarded account until completion.',
            'legal_structure' => 'DIRECT_CO_OWNERSHIP',
            'regulatory_status' => 'NOT_REQUIRED',
            'ownership_type' => 'DIRECT_CO_OWNERSHIP',
            'verified_at' => now()->subDays(12),
            'published_at' => now()->subDays(10),
        ]);

        $holders = array_slice($this->participantIds, 0, 120);
        $units = $this->assignUnits($holders, 408);

        $this->createParticipations($opportunity, $units, 2_000_000, 8);
    }

    private function seedSecondaryOpportunities(): void
    {
        $specs = [
            [
                'slug' => 'solar-for-small-businesses', 'title' => 'Solar for Small Businesses',
                'summary' => 'Pool funds to install rooftop solar for 40 market traders, paid back from energy savings.',
                'cover' => '/images/cover-solar.webp', 'category' => 'Energy', 'model' => 'MANAGED_ASSET',
                'target' => 950_000_000, 'unit' => 500_000, 'total_units' => 1900,
                'units' => 1216, 'participants' => 52, 'days' => 19, 'location' => 'Kano, Nigeria',
                'supplier' => 'Sahel Solar Systems',
                'description' => 'A managed portfolio of rooftop solar installations across 40 small businesses in Kano. Each business signs an energy-as-a-service contract, and collections flow to asset owners monthly.',
                'risk' => 'Collection risk from small businesses; equipment performance depends on maintenance.',
            ],
            [
                'slug' => 'warehouse-storage', 'title' => 'Warehouse Storage',
                'summary' => 'Own a share of a 2,000sqm dry storage facility near the Apapa port corridor.',
                'cover' => '/images/cover-warehouse.webp', 'category' => 'Logistics', 'model' => 'MANAGED_ASSET',
                'target' => 640_000_000, 'unit' => 400_000, 'total_units' => 1600,
                'units' => 656, 'participants' => 38, 'days' => 27, 'location' => 'Apapa, Lagos',
                'supplier' => 'Apapa Storage Partners',
                'description' => 'A 2,000sqm dry warehouse with 14 loading bays, leased to two FMCG distributors on three-year terms. Owners receive net rent quarterly.',
                'risk' => 'Vacancy and tenant credit risk. Warehouse repairs are funded from gross rent before distribution.',
            ],
            [
                'slug' => 'logistics-fleet', 'title' => 'Logistics Fleet',
                'summary' => 'Co-own ten refrigerated vans on contract to a national grocery chain.',
                'cover' => '/images/cover-logistics.webp', 'category' => 'Logistics', 'model' => 'MANAGED_ASSET',
                'target' => 1_800_000_000, 'unit' => 1_000_000, 'total_units' => 1800,
                'units' => 684, 'participants' => 61, 'days' => 33, 'location' => 'Lagos, Nigeria',
                'supplier' => 'ColdChain Mobility',
                'description' => 'Ten refrigerated vans under a three-year logistics contract with a national grocery chain. Net contract revenue is distributed monthly after maintenance and insurance.',
                'risk' => 'Vehicle depreciation, accidents and contract renewal risk.',
            ],
            [
                'slug' => 'palm-oil-refining', 'title' => 'Palm Oil Refining',
                'summary' => 'Bulk-buy crude palm oil from Cross River cooperatives and refine for wholesale.',
                'cover' => '/images/cover-oil.webp', 'category' => 'Agriculture', 'model' => 'COLLECTIVE_PURCHASE',
                'target' => 420_000_000, 'unit' => 350_000, 'total_units' => 1200,
                'units' => 912, 'participants' => 74, 'days' => 12, 'location' => 'Cross River, Nigeria',
                'supplier' => 'Cross River Palm Cooperative',
                'description' => 'Aggregated purchase of crude palm oil with refining and wholesale offtake already negotiated. Participants own the refined oil pro-rata.',
                'risk' => 'Commodity price swings and refining yield losses.',
            ],
            [
                'slug' => 'cocoa-export-cycle', 'title' => 'Cocoa Export Cycle',
                'summary' => 'Fund a verified cocoa export cycle from Ondo State to European buyers.',
                'cover' => '/images/cover-cocoa.webp', 'category' => 'Commodities', 'model' => 'COLLECTIVE_PURCHASE',
                'target' => 1_100_000_000, 'unit' => 500_000, 'total_units' => 2200,
                'units' => 1144, 'participants' => 66, 'days' => 22, 'location' => 'Ondo State, Nigeria',
                'supplier' => 'Ondo Cocoa Growers Union',
                'description' => 'A single export cycle: buy graded cocoa from a growers union, process and export against a confirmed letter of credit. Proceeds are distributed after buyer acceptance.',
                'risk' => 'Quality rejection, shipping delays and currency movement.',
            ],
            [
                'slug' => 'fresh-produce-hub', 'title' => 'Fresh Produce Hub',
                'summary' => 'A cold-chain produce hub supplying restaurants and hotels across Lagos.',
                'cover' => '/images/cover-fruit.webp', 'category' => 'Food & Beverage', 'model' => 'MANAGED_ASSET',
                'target' => 320_000_000, 'unit' => 200_000, 'total_units' => 1600,
                'units' => 1296, 'participants' => 83, 'days' => 7, 'location' => 'Ikeja, Lagos',
                'supplier' => 'GreenBasket Distribution',
                'description' => 'Cold storage, sorting and last-mile distribution for fresh produce sold to 60 restaurants and hotels. Owners share net trading margin each month.',
                'risk' => 'Perishable inventory losses and customer concentration.',
            ],
        ];

        foreach ($specs as $spec) {
            $opportunity = Opportunity::create([
                'slug' => $spec['slug'],
                'title' => $spec['title'],
                'summary' => $spec['summary'],
                'description' => $spec['description'],
                'cover_image' => $spec['cover'],
                'category' => $spec['category'],
                'model' => $spec['model'],
                'participation_method' => 'UNITS',
                'status' => 'FUNDING',
                'creator_id' => $this->admin->id,
                'creator_source' => 'PLATFORM',
                'supplier_name' => $spec['supplier'],
                'supplier_verified' => true,
                'target_amount' => $spec['target'],
                'committed_amount' => $spec['units'] * $spec['unit'],
                'unit_price' => $spec['unit'],
                'total_units' => $spec['total_units'],
                'units_committed' => $spec['units'],
                'min_contribution' => $spec['unit'],
                'min_units' => 1,
                'participant_count' => $spec['participants'],
                'funding_deadline' => now()->addDays($spec['days']),
                'location' => $spec['location'],
                'fee_bps' => 350,
                'policy_version' => 3,
                'policy_terms' => json_encode(['feeBps' => 350, 'fundingPolicy' => 'PARTIAL_ALLOWED', 'version' => 3]),
                'allows_partial_funding' => true,
                'allow_cancellation' => true,
                'risk_notes' => $spec['risk'],
                'acquisition_conditions' => 'Funds release only after supplier verification, inspection and a signed acquisition record.',
                'legal_structure' => $spec['model'] === 'MANAGED_ASSET' ? 'BENEFICIAL' : 'CONTRACTUAL_ECONOMIC',
                'regulatory_status' => 'NOT_REQUIRED',
                'ownership_type' => $spec['model'] === 'MANAGED_ASSET' ? 'BENEFICIAL' : 'PHYSICAL_QUANTITY',
                'verified_at' => now()->subDays(6),
                'published_at' => now()->subDays(5),
            ]);

            $holders = $this->pickParticipants($spec['participants'], $opportunity->id);
            $units = $this->assignUnits($holders, $spec['units']);

            $this->createParticipations($opportunity, $units, $spec['unit'], 5);
        }
    }

    private function seedAcquiringOpportunity(): void
    {
        $opportunity = Opportunity::create([
            'slug' => 'kano-cold-storage',
            'title' => 'Kano Cold Storage',
            'summary' => 'A fully funded 400-pallet cold room serving poultry and dairy distributors.',
            'description' => 'A fully funded cold storage facility in Kano with anchor tenants signed. Acquisition is in progress and the supplier payout is awaiting finance approval.',
            'cover_image' => '/images/cover-warehouse.webp',
            'category' => 'Logistics',
            'model' => 'MANAGED_ASSET',
            'participation_method' => 'UNITS',
            'status' => 'ACQUIRING',
            'creator_id' => $this->admin->id,
            'creator_source' => 'PLATFORM',
            'supplier_name' => 'Kano ColdChain Builders',
            'supplier_verified' => true,
            'target_amount' => 400_000_000,
            'committed_amount' => 400_000_000,
            'unit_price' => 2_000_000,
            'total_units' => 200,
            'units_committed' => 200,
            'min_contribution' => 2_000_000,
            'min_units' => 1,
            'participant_count' => 30,
            'funding_deadline' => now()->subDays(3),
            'location' => 'Kano, Nigeria',
            'fee_bps' => 350,
            'allows_partial_funding' => true,
            'allow_cancellation' => false,
            'risk_notes' => 'Construction and commissioning delays could push the first distribution.',
            'acquisition_conditions' => 'Release on commissioning certificate and tenant lease confirmation.',
            'legal_structure' => 'BENEFICIAL',
            'ownership_type' => 'BENEFICIAL',
            'verified_at' => now()->subDays(30),
            'published_at' => now()->subDays(28),
            'funded_at' => now()->subDays(4),
        ]);

        $holders = $this->pickParticipants(30, $opportunity->id);
        $units = $this->assignUnits($holders, 200);
        $participations = $this->createParticipations($opportunity, $units, 2_000_000, 18);

        $fee = Money::bps(400_000_000, 350);
        $this->ledger->post(Ledger::platformFee($fee), $opportunity);

        $payout = SupplierPayout::create([
            'reference' => References::make('PAY'),
            'opportunity_id' => $opportunity->id,
            'amount' => 386_000_000,
            'status' => 'AWAITING_APPROVAL',
            'requested_by_id' => $this->ops->id,
            'note' => '70% supplier milestone after factory acceptance test.',
        ]);

        Approval::create([
            'type' => 'SUPPLIER_PAYOUT',
            'entity_id' => (string) $payout->id,
            'summary' => 'Pay Kano ColdChain Builders '.Money::format(386_000_000),
            'amount' => 386_000_000,
            'status' => 'PENDING',
            'requested_by_id' => $this->ops->id,
        ]);

        unset($participations);
    }

    private function seedCompletedAsset(): void
    {
        $opportunity = Opportunity::create([
            'slug' => 'rice-mill-expansion',
            'title' => 'Rice Mill Expansion',
            'summary' => 'Completed collective acquisition of a rice milling line in Ebonyi State.',
            'description' => 'Owners funded a second milling line for a cooperative mill in Ebonyi. The asset is installed, operating and distributing milling fees monthly.',
            'cover_image' => '/images/cover-rice-grain.webp',
            'category' => 'Agriculture',
            'model' => 'MANAGED_ASSET',
            'participation_method' => 'UNITS',
            'status' => 'COMPLETED',
            'creator_id' => $this->admin->id,
            'creator_source' => 'PLATFORM',
            'supplier_name' => 'Ebonyi Millers Cooperative',
            'supplier_verified' => true,
            'target_amount' => 500_000_000,
            'committed_amount' => 500_000_000,
            'unit_price' => 5_000_000,
            'total_units' => 100,
            'units_committed' => 100,
            'min_contribution' => 5_000_000,
            'min_units' => 1,
            'participant_count' => 40,
            'funding_deadline' => now()->subDays(70),
            'location' => 'Ebonyi State, Nigeria',
            'fee_bps' => 350,
            'allows_partial_funding' => false,
            'allow_cancellation' => false,
            'risk_notes' => 'Equipment downtime affects distributions.',
            'acquisition_conditions' => 'Release on installation and commissioning.',
            'legal_structure' => 'DIRECT_CO_OWNERSHIP',
            'ownership_type' => 'DIRECT_CO_OWNERSHIP',
            'verified_at' => now()->subDays(80),
            'published_at' => now()->subDays(78),
            'funded_at' => now()->subDays(65),
            'acquired_at' => now()->subDays(55),
            'completed_at' => now()->subDays(20),
        ]);

        $holders = $this->pickParticipants(40, $opportunity->id);
        $units = $this->assignUnits($holders, 100);
        $participations = $this->createParticipations($opportunity, $units, 5_000_000, 55);
        $fee = Money::bps(500_000_000, 350);
        $this->ledger->post(Ledger::platformFee($fee), $opportunity);

        $asset = Asset::create([
            'reference' => References::make('AST'),
            'opportunity_id' => $opportunity->id,
            'name' => 'Ebonyi Rice Mill — Line 2',
            'category' => 'Agriculture',
            'description' => 'A 12-tonne-per-hour rice milling line with destoner, polisher and bagging unit, installed at the Ebonyi Millers Cooperative.',
            'cover_image' => '/images/cover-rice-grain.webp',
            'acquisition_price' => 482_500_000,
            'current_valuation' => 530_000_000,
            'valuation_source' => 'Independent valuation, Adeyemi & Co.',
            'valuation_at' => now()->subDays(12),
            'acquisition_date' => now()->subDays(55),
            'location' => 'Ebonyi State, Nigeria',
            'legal_structure' => 'DIRECT_CO_OWNERSHIP',
            'custodian' => 'Ebonyi Millers Cooperative',
            'status' => 'ACTIVE',
            'total_units' => 100,
        ]);

        foreach ($participations as $participation) {
            OwnershipInterest::create([
                'asset_id' => $asset->id,
                'user_id' => $participation->user_id,
                'participation_id' => $participation->id,
                'units' => $participation->units,
                'cost_basis' => $participation->amount,
                'ownership_type' => 'DIRECT_CO_OWNERSHIP',
            ]);
        }

        $this->ledger->post(Ledger::acquisitionCommitted(482_500_000), $opportunity);
        $this->ledger->post(Ledger::supplierPayout(482_500_000), $opportunity);
        $this->ledger->post(Ledger::unallocatedProceeds(12_400_000), $opportunity);
        $this->ledger->post(Ledger::distributionDeclared(12_400_000), $opportunity);

        $asset->reports()->create([
            'title' => 'Q3 milling operations report',
            'kind' => 'INCOME',
            'body' => 'The line processed 1,840 tonnes in the quarter at 92% availability. Net milling fees of ₦12.4m were declared to owners.',
            'verified' => true,
            'published_at' => now()->subDays(10),
        ]);

        $asset->valuations()->create([
            'value' => 530_000_000,
            'source' => 'Adeyemi & Co. independent valuation',
            'verified' => true,
        ]);
    }

    private function seedRefund(Opportunity $rice, array $participations): void
    {
        $participation = collect($participations)
            ->first(fn (Participation $item) => $item->user_id !== $this->demo->id && $item->units >= 3);

        if (! $participation) {
            return;
        }

        $participation->update(['status' => 'REFUND_PENDING']);

        $refund = Refund::create([
            'reference' => References::make('REF'),
            'user_id' => $participation->user_id,
            'opportunity_id' => $rice->id,
            'participation_id' => $participation->id,
            'amount' => 3_000_000,
            'reason' => 'PARTICIPANT_REQUEST',
            'detail' => 'Participant is relocating and requested a pre-acquisition refund.',
            'status' => 'REQUESTED',
            'requested_by_id' => $participation->user_id,
        ]);

        Approval::create([
            'type' => 'REFUND',
            'entity_id' => (string) $refund->id,
            'summary' => 'Refund '.Money::format(3_000_000).' for '.$rice->title,
            'amount' => 3_000_000,
            'status' => 'PENDING',
            'requested_by_id' => $participation->user_id,
        ]);
    }

    private function seedNotifications(): void
    {
        $items = [
            ['Funding update', 'Bulk Rice Purchase is now 72% funded. 243 neighbours have joined.', 'INFO', '/opportunities/bulk-rice-purchase', null],
            ['Contribution confirmed', 'Your ₦100,000 contribution to Bulk Rice Purchase was confirmed.', 'SUCCESS', '/participations', null],
            ['Farmland Cooperative update', 'Title search completed. Acquisition is on track for next month.', 'INFO', '/opportunities/farmland-cooperative', null],
            ['Verify your BVN', 'Complete identity verification to increase your contribution limit.', 'WARNING', '/wallet', now()->subDays(2)],
            ['Welcome to ShareUp', 'Your account is ready. Explore live opportunities to start owning together.', 'INFO', '/discover', now()->subDays(8)],
        ];

        foreach ($items as [$title, $body, $kind, $href, $readAt]) {
            Notification::create([
                'user_id' => $this->demo->id,
                'title' => $title,
                'body' => $body,
                'kind' => $kind,
                'href' => $href,
                'read_at' => $readAt,
            ]);
        }
    }

    private function seedWaitlist(): void
    {
        $logistics = Opportunity::where('slug', 'logistics-fleet')->first();

        if ($logistics) {
            WaitlistEntry::create([
                'opportunity_id' => $logistics->id,
                'user_id' => $this->demo->id,
            ]);
        }
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  array<int, int>  $pinned
     * @return array<int, int> user id => units
     */
    private function assignUnits(array $userIds, int $totalUnits, array $pinned = []): array
    {
        $userIds = array_values(array_unique($userIds));
        $units = array_fill_keys($userIds, 1);
        $assigned = count($userIds);

        foreach ($pinned as $userId => $target) {
            if (! isset($units[$userId])) {
                continue;
            }
            $assigned += max(0, $target - 1);
            $units[$userId] = max(1, $target);
        }

        $remaining = $totalUnits - $assigned;

        if ($remaining < 0) {
            throw new \RuntimeException('Not enough units to give every holder a minimum stake.');
        }

        $eligible = array_values(array_filter($userIds, fn (int $id) => ! array_key_exists($id, $pinned)));
        $index = 0;

        while ($remaining > 0) {
            $units[$eligible[$index % count($eligible)]]++;
            $index++;
            $remaining--;
        }

        return $units;
    }

    /**
     * @param  array<int, int>  $unitsByUser
     * @return array<int, Participation>
     */
    private function createParticipations(Opportunity $opportunity, array $unitsByUser, int $unitPrice, int $daysAgo): array
    {
        $participations = [];

        foreach ($unitsByUser as $userId => $units) {
            $amount = $units * $unitPrice;

            $participation = Participation::create([
                'reference' => References::make('PAR'),
                'opportunity_id' => $opportunity->id,
                'user_id' => $userId,
                'amount' => $amount,
                'units' => $units,
                'quantity' => $opportunity->units_per_package ? intdiv($units, $opportunity->units_per_package) : $units,
                'status' => 'CONFIRMED',
                'idempotency_key' => 'seed:'.$opportunity->id.':'.$userId,
                'entitlement_units' => $units,
                'confirmed_at' => now()->subDays($daysAgo),
                'created_at' => now()->subDays($daysAgo),
                'updated_at' => now()->subDays($daysAgo),
            ]);

            Transaction::create([
                'reference' => References::make('TXN'),
                'user_id' => $userId,
                'opportunity_id' => $opportunity->id,
                'participation_id' => $participation->id,
                'type' => 'CONTRIBUTION',
                'amount' => $amount,
                'currency' => 'NGN',
                'status' => 'PAID',
                'provider' => 'SANDBOX',
                'idempotency_key' => 'seed-txn:'.$opportunity->id.':'.$userId,
                'completed_at' => now()->subDays($daysAgo),
                'created_at' => now()->subDays($daysAgo),
                'updated_at' => now()->subDays($daysAgo),
            ]);

            $this->ledger->post(Ledger::contributionReceived($amount), $opportunity);

            $participations[] = $participation;
        }

        return $participations;
    }

    /** @return array<int, int> */
    private function pickParticipants(int $count, int $salt): array
    {
        $offset = ($salt * 17) % max(1, count($this->participantIds) - $count);
        $picked = array_slice($this->participantIds, $offset, $count);

        while (count($picked) < $count) {
            $picked[] = $this->participantIds[count($picked) % count($this->participantIds)];
        }

        return array_values(array_unique($picked));
    }
}
