<?php

namespace JarredCain\CanvasLms\Tests\Unit\Sis;

use JarredCain\CanvasLms\Models\SisImport;
use JarredCain\CanvasLms\Models\User;
use JarredCain\CanvasLms\Sis\SisImporter;
use JarredCain\CanvasLms\Sis\SisUserCsvBuilder;
use JarredCain\CanvasLms\Tests\TestCase;

class SisUserCsvBuilderTest extends TestCase
{
    public function test_produces_header_row(): void
    {
        $csv   = SisUserCsvBuilder::make()->suspend('u1')->toCsv();
        $lines = $this->csvLines($csv);

        $this->assertSame(['user_id', 'status'], $lines[0]);
    }

    public function test_suspend_adds_suspended_row(): void
    {
        $csv   = SisUserCsvBuilder::make()->suspend('sis_001')->toCsv();
        $lines = $this->csvLines($csv);

        $this->assertSame(['sis_001', 'suspended'], $lines[1]);
    }

    public function test_activate_adds_active_row(): void
    {
        $csv   = SisUserCsvBuilder::make()->activate('sis_002')->toCsv();
        $lines = $this->csvLines($csv);

        $this->assertSame(['sis_002', 'active'], $lines[1]);
    }

    public function test_delete_adds_deleted_row(): void
    {
        $csv   = SisUserCsvBuilder::make()->delete('sis_003')->toCsv();
        $lines = $this->csvLines($csv);

        $this->assertSame(['sis_003', 'deleted'], $lines[1]);
    }

    public function test_multiple_rows_are_all_written(): void
    {
        $csv   = SisUserCsvBuilder::make()
            ->suspend('u1')
            ->activate('u2')
            ->delete('u3')
            ->toCsv();

        $lines = $this->csvLines($csv);

        $this->assertCount(4, $lines); // header + 3 data rows
        $this->assertSame(['u1', 'suspended'], $lines[1]);
        $this->assertSame(['u2', 'active'],    $lines[2]);
        $this->assertSame(['u3', 'deleted'],   $lines[3]);
    }

    public function test_is_immutable_between_calls(): void
    {
        $base = SisUserCsvBuilder::make()->suspend('u1');
        $extended = $base->activate('u2');

        $this->assertSame(1, $base->count());
        $this->assertSame(2, $extended->count());
    }

    public function test_add_row_rejects_invalid_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid SIS user status 'invalid'");

        SisUserCsvBuilder::make()->addRow('u1', 'invalid');
    }

    public function test_suspend_users_from_model_instances(): void
    {
        $user1 = new User(['id' => '1', 'sis_user_id' => 'sis_001']);
        $user2 = new User(['id' => '2', 'sis_user_id' => 'sis_002']);

        $csv   = SisUserCsvBuilder::make()->suspendUsers([$user1, $user2])->toCsv();
        $lines = $this->csvLines($csv);

        $this->assertCount(3, $lines);
        $this->assertSame(['sis_001', 'suspended'], $lines[1]);
        $this->assertSame(['sis_002', 'suspended'], $lines[2]);
    }

    public function test_activate_users_from_model_instances(): void
    {
        $user = new User(['id' => '5', 'sis_user_id' => 'sis_005']);

        $csv   = SisUserCsvBuilder::make()->activateUsers([$user])->toCsv();
        $lines = $this->csvLines($csv);

        $this->assertSame(['sis_005', 'active'], $lines[1]);
    }

    public function test_suspend_users_throws_when_sis_user_id_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has no sis_user_id');

        $user = new User(['id' => '99']);
        SisUserCsvBuilder::make()->suspendUsers([$user])->toCsv();
    }

    public function test_submit_via_calls_importer_with_csv(): void
    {
        $expectedCsv = SisUserCsvBuilder::make()->suspend('u1')->toCsv();

        $sisImport = new SisImport(['id' => '10', 'workflow_state' => 'imported', 'progress' => 100]);

        $importer = $this->createMock(SisImporter::class);

        // fromCsv returns a clone of the importer — mock it to return itself
        $importer->expects($this->once())
            ->method('fromCsv')
            ->with($expectedCsv, 'users.csv')
            ->willReturnSelf();

        $importer->expects($this->once())
            ->method('submit')
            ->willReturn($sisImport);

        $result = SisUserCsvBuilder::make()->suspend('u1')->submitVia($importer);

        $this->assertSame($sisImport, $result);
    }

    public function test_submit_via_throws_when_empty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No rows to submit');

        $importer = $this->createMock(SisImporter::class);
        SisUserCsvBuilder::make()->submitVia($importer);
    }

    public function test_to_file_writes_csv(): void
    {
        $path = sys_get_temp_dir() . '/sis_users_test_' . uniqid() . '.csv';

        SisUserCsvBuilder::make()->suspend('u1')->activate('u2')->toFile($path);

        $this->assertFileExists($path);

        $lines = $this->csvLines(file_get_contents($path));
        $this->assertSame(['user_id', 'status'], $lines[0]);
        $this->assertSame(['u1', 'suspended'],   $lines[1]);
        $this->assertSame(['u2', 'active'],      $lines[2]);

        unlink($path);
    }

    public function test_count_and_is_empty(): void
    {
        $builder = SisUserCsvBuilder::make();
        $this->assertTrue($builder->isEmpty());
        $this->assertSame(0, $builder->count());

        $builder = $builder->suspend('u1')->delete('u2');
        $this->assertFalse($builder->isEmpty());
        $this->assertSame(2, $builder->count());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Parse a CSV string into an array of rows (each row is an array of strings). */
    private function csvLines(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }
}
