// Biaya layanan aplikasi bertingkat (§29.3) — rumus yang sama dengan PaymentManager::serviceFee() di server.
// tiers: [[batas total, biaya], ...] urut naik; di atas batas terakhir: persen dari total (basis poin, 1% = 100),
// dibulatkan ke atas per rupiah dan tidak kurang dari biaya tingkatan terakhir. Server tetap penentu akhir.
export function serviceFee(total, tiers, aboveBasisPoints) {
    if (!(total > 0)) return 0;
    for (const [limit, fee] of tiers) {
        if (total <= limit) return fee;
    }
    const last = tiers.length ? tiers[tiers.length - 1][1] : 0;
    const percent = aboveBasisPoints > 0 ? Math.floor((total * aboveBasisPoints + 9999) / 10000) : 0;

    return Math.max(last, percent);
}
