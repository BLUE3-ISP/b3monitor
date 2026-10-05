<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Um UPDATE que não muda nada NÃO sobe a revisão do bundle (B8; achado do teste de idempotência do semeador do laboratório).
|
| O gatilho da 0.1.23 disparava em todo UPDATE, então reescrever um check ou um assignment com os MESMOS valores fazia toda sonda
| afetada baixar o bundle de novo à toa, e a revisão deixava de dizer "a configuração mudou". Agora o UPDATE só conta quando a linha
| de fato é diferente (`OLD IS DISTINCT FROM NEW`); INSERT e DELETE continuam contando sempre.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS assignments_revisao ON assignments');
        DB::unprepared('DROP TRIGGER IF EXISTS checks_revisao ON checks');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER assignments_revisao_ins_del AFTER INSERT OR DELETE ON assignments
            FOR EACH ROW EXECUTE FUNCTION b3mon_bump_bundle_revision()
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER assignments_revisao_upd AFTER UPDATE ON assignments
            FOR EACH ROW WHEN (OLD.* IS DISTINCT FROM NEW.*) EXECUTE FUNCTION b3mon_bump_bundle_revision()
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER checks_revisao_upd AFTER UPDATE ON checks
            FOR EACH ROW WHEN (OLD.* IS DISTINCT FROM NEW.*) EXECUTE FUNCTION b3mon_bump_bundle_revision()
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER checks_revisao_del AFTER DELETE ON checks
            FOR EACH ROW EXECUTE FUNCTION b3mon_bump_bundle_revision()
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS checks_revisao_del ON checks');
        DB::unprepared('DROP TRIGGER IF EXISTS checks_revisao_upd ON checks');
        DB::unprepared('DROP TRIGGER IF EXISTS assignments_revisao_upd ON assignments');
        DB::unprepared('DROP TRIGGER IF EXISTS assignments_revisao_ins_del ON assignments');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER assignments_revisao AFTER INSERT OR UPDATE OR DELETE ON assignments
            FOR EACH ROW EXECUTE FUNCTION b3mon_bump_bundle_revision()
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER checks_revisao AFTER UPDATE OR DELETE ON checks
            FOR EACH ROW EXECUTE FUNCTION b3mon_bump_bundle_revision()
        SQL);
    }
};
