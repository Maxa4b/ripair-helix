<?php

namespace Tests\Feature;

use App\Models\Prospecting\Company;
use App\Services\Prospecting\SireneProspectingImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SireneProspectingImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_only_relevant_b2b_companies_from_sirene_csv(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sirene-prospecting-');
        $this->assertIsString($file);

        file_put_contents($file, implode("\n", [
            'siren;siret;denominationUniteLegale;etatAdministratifEtablissement;etatAdministratifUniteLegale;activitePrincipaleEtablissement;numeroVoieEtablissement;typeVoieEtablissement;libelleVoieEtablissement;codePostalEtablissement;libelleCommuneEtablissement',
            '123456789;12345678900011;ALPHA ELECTRONIQUE;A;A;2611Z;10;RUE;DES CARTES;38000;GRENOBLE',
            '987654321;98765432100011;PHONE REPAIR EXPRESS;A;A;9512Z;5;RUE;DU GSM;31000;TOULOUSE',
            '111111111;11111111100011;BOULANGERIE DUPONT;A;A;1071C;1;RUE;DU PAIN;75000;PARIS',
        ]));

        try {
            $job = app(SireneProspectingImportService::class)->importFromSource($file, [
                'limit' => 1000,
                'min_score' => 55,
            ]);

            $this->assertSame(1, Company::query()->count());
            $company = Company::query()->first();
            $this->assertInstanceOf(Company::class, $company);
            $this->assertSame('ALPHA ELECTRONIQUE', $company->name);
            $this->assertSame('38000', $company->postal_code);
            $this->assertSame('GRENOBLE', $company->city);
            $this->assertSame('sirene_auto', $company->source);
            $this->assertGreaterThanOrEqual(55, $company->relevance_score);

            $this->assertSame(1, $job->rows_created);
            $this->assertSame(2, $job->rows_rejected);
        } finally {
            @unlink($file);
        }
    }
}
