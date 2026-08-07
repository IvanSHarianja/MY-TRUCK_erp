<x-filament-panels::page>
    <div class="report-card">
        <div class="report-header">
            <div class="report-header-title">Role Akses</div>
            <div class="report-header-subtitle">
                @if ($canEdit)
                    <span style="color: var(--mt-accent-green, #16a34a); font-weight: 600;">✎ Mode Edit</span>
                    — sebagai Owner, klik checkbox untuk memberi/mencabut akses. Perubahan langsung tersimpan.
                @else
                    Referensi role & izin. Hanya Owner yang bisa mengubah matrix.
                @endif
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="report-table" style="min-width: 800px;">
                <thead>
                    <tr>
                        <th style="width: 40%;">Aksi</th>
                        @foreach ($roles as $role)
                            <th class="text-right" style="width: 15%;">
                                {{ $role->label() }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($grouped as $groupLabel => $rows)
                        <tr>
                            <td colspan="{{ count($roles) + 1 }}" style="background: rgba(127, 127, 127, 0.08); font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 0.75px; color: var(--mt-text-muted, #6b7280); padding: 8px 12px;">
                                {{ $groupLabel }}
                            </td>
                        </tr>
                        @foreach ($rows as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                @foreach ($roles as $role)
                                    <td class="text-right">
                                        @php
                                            $isAllowed  = (bool) ($row['allowed_by_role'][$role->value] ?? false);
                                            $isOwner    = $role->value === 'owner';
                                            $editable   = $canEdit && ! $isOwner;
                                        @endphp

                                        @if ($editable)
                                            <label style="display: inline-flex; align-items: center; cursor: pointer; padding: 4px 8px; border-radius: 4px; transition: background 0.15s;"
                                                   onmouseover="this.style.background='rgba(127,127,127,0.1)'"
                                                   onmouseout="this.style.background='transparent'">
                                                <input type="checkbox"
                                                       @if ($isAllowed) checked @endif
                                                       wire:click="togglePermission('{{ $role->value }}', '{{ $row['permission']->value }}', {{ $isAllowed ? 'false' : 'true' }})"
                                                       style="width: 18px; height: 18px; cursor: pointer; accent-color: {{ $isAllowed ? '#16a34a' : '#94a3b8' }};">
                                            </label>
                                        @else
                                            @if ($isAllowed)
                                                <span style="color: var(--mt-accent-green, #16a34a); font-weight: 700; font-size: 16px;"
                                                      @if ($isOwner) title="Owner immutable — selalu semua akses" @endif>✓</span>
                                            @else
                                                <span style="opacity: 0.2;">—</span>
                                            @endif
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="margin-top: 16px; padding: 12px; background: rgba(59, 130, 246, 0.08); border-radius: 6px; font-size: 12px; line-height: 1.6;">
            <strong>Catatan:</strong>
            <ul style="margin: 6px 0 0 18px; padding: 0;">
                <li>Perubahan berlaku <strong>seketika</strong> untuk request berikutnya (user aktif tidak logout, tapi tombol/menu akan menghilang/muncul otomatis).</li>
                <li>Kolom <strong>Owner</strong> tidak bisa diubah — Owner selalu punya akses penuh sebagai safety agar tidak lockout diri sendiri.</li>
                <li>Klik <em>"Reset ke Default"</em> di kanan atas untuk mengembalikan matrix PT ini ke setelan default.</li>
                <li>Business rule tambahan (mis. "tidak bisa demote owner terakhir") tetap di-enforce di layer aplikasi.</li>
            </ul>
        </div>
    </div>
</x-filament-panels::page>
