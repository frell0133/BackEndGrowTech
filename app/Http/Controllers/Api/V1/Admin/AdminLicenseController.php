<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminLicenseController extends Controller
{
    use ApiResponse;

    public function index(Request $request, string $id)
    {
        // TODO: list license/credential per product; filter status & q
        return $this->ok(['product_id' => $id, 'todo' => true]);
    }

    public function summary(string $id)
    {
        // TODO: total available/used/revoked
        return $this->ok(['product_id' => $id, 'todo' => true]);
    }

    public function store(Request $request, string $id)
    {
        // TODO: add manual (single/batch)
        return $this->ok(['product_id' => $id, 'todo' => true]);
    }

    public function upload(Request $request, string $id)
    {
        // TODO: upload CSV/TXT parse + insert + report duplicates/errors
        return $this->ok(['product_id' => $id, 'todo' => true]);
    }

    public function checkDuplicates(Request $request)
    {
        // TODO: check duplicates before insert
        return $this->ok(['todo' => true]);
    }

    public function takeStock(Request $request, string $id)
    {
        // TODO: take-stock partial/all + create proof
        return $this->ok(['product_id' => $id, 'todo' => true]);
    }

    public function proofList(Request $request)
    {
        // TODO: list proofs filter date/user/product
        return $this->ok(['todo' => true]);
    }

    public function proofDownload(string $proof_id)
    {
        // TODO: download PDF proof
        return $this->ok(['proof_id' => $proof_id, 'todo' => true]);
    }
}
