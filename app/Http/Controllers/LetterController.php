<?php

namespace App\Http\Controllers;

use App\Modules\Administration\LetterAccess;
use App\Modules\Administration\LetterManager;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class LetterController extends Controller
{
    public function __construct(private LetterAccess $access, private LetterManager $manager, private TenantQuery $query) {}

    private function portal(Request $request): bool
    {
        return $request->is('portal', 'portal/*');
    }

    public function index(Request $request)
    {
        $portal = $this->portal($request);
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(LetterManager::STATUSES))], 'number' => ['nullable', 'string', 'max:80']]);
        $rows = $this->access->visible($request->user(), $portal);
        if (! empty($filters['status'])) {
            $rows->where('status', $filters['status']);
        }
        if (! empty($filters['number'])) {
            $rows->whereIn('id', $this->query->table('issued_documents')->where('number', 'like', '%'.addcslashes($filters['number'], '%_\\').'%')->select('request_id'));
        }
        $rows = $rows->orderByDesc('id')->paginate(15)->withQueryString();
        $documents = $this->query->table('issued_documents')->whereIn('request_id', $rows->pluck('id'))->select('request_id', 'number', 'pdf_status')->get()->keyBy('request_id');

        return view('letters.index', compact('rows', 'portal', 'documents'));
    }

    public function services(Request $request)
    {
        $portal = $this->portal($request);
        if ($portal) {
            $person = $this->access->applicant($request->user());
            $services = $this->manager->services()->orderBy('title')->paginate(15);
        } else {
            $this->access->authorize($request->user(), 'letter.configure');
            $person = null;
            $services = $this->query->table('service_definitions')->orderBy('title')->paginate(15);
        }

        return view('letters.services', compact('portal', 'person', 'services'));
    }

    public function configure(Request $request, int $id)
    {
        $this->manager->configure($request->user(), $id, $request->all());

        return back()->with('success', 'Aturan surat disimpan untuk pengajuan berikutnya.');
    }

    public function create(Request $request, int $id)
    {
        $person = $this->access->applicant($request->user());
        $service = $this->manager->services()->where('id', $id)->first();
        abort_unless($service, 404);
        $household = $person ? $this->query->table('households')->where('id', $person->household_id)->first() : null;
        $identity = $person ? $this->manager->identity($person, $household) : null;

        return view('letters.create', compact('service', 'person', 'household', 'identity'));
    }

    public function store(Request $request)
    {
        $id = $this->manager->submit($request->user(), $request->all());

        return redirect('/portal/surat/'.$id)->with('success', 'Pengajuan terkirim. Penyarikan akan memeriksa surat Anda.');
    }

    public function show(Request $request, int $id)
    {
        $portal = $this->portal($request);
        $row = $this->access->visible($request->user(), $portal)->where('id', $id)->first();
        abort_unless($row, 404);
        $identity = LetterManager::unpack($row->identity_snapshot);
        $service = LetterManager::unpack($row->service_snapshot);
        $document = $this->query->table('issued_documents')->where('request_id', $id)->select('number', 'pdf_status')->first();
        $steps = $this->query->table('letter_request_steps')->where('request_id', $id)->orderBy('id')->get();
        $actors = DB::table('users')->whereIn('id', $steps->pluck('actor_id'))->pluck('name', 'id');
        $canReview = ! $portal && $this->access->canReview($request->user(), $row);

        return view('letters.show', compact('row', 'portal', 'identity', 'service', 'document', 'steps', 'actors', 'canReview'));
    }

    public function review(Request $request, int $id)
    {
        $status = $this->manager->review($request->user(), $id, $request->all());

        return back()->with('success', $status === 'CONFLICT' ? 'Identitas sudah berubah. Pemohon perlu mengajukan ulang menggunakan data terbaru.' : 'Pemeriksaan disimpan: '.LetterManager::STATUSES[$status].'.');
    }

    public function cancel(Request $request, int $id)
    {
        $this->manager->cancel($request->user(), $id);

        return back()->with('success', 'Pengajuan dibatalkan. Riwayat tetap tersimpan.');
    }

    public function retry(Request $request, int $id)
    {
        $this->manager->retryPdf($request->user(), $id);

        return back()->with('success', 'Pembuatan PDF dijadwalkan ulang.');
    }

    public function download(Request $request, int $id, Audit $audit, CurrentBanjar $tenant)
    {
        $row = $this->access->visible($request->user(), $this->portal($request))->where('id', $id)->first();
        abort_unless($row, 404);
        $document = $this->query->table('issued_documents')->where('request_id', $id)->where('pdf_status', 'READY')->first();
        abort_unless($document, 404, 'PDF belum tersedia.');
        $bytes = Crypt::decryptString($document->pdf_encrypted);
        abort_unless(hash_equals($document->sha256, hash('sha256', $bytes)), 409, 'Arsip perlu diperiksa oleh pengurus.');
        $audit->record($tenant->id(), $request->user()->id, 'letter.downloaded', 'issued_documents', $document->id);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="surat-'.str_replace('/', '-', $document->number).'.pdf"']);
    }
}
