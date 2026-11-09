import test from 'node:test';
import assert from 'node:assert/strict';
import { serviceFee } from '../resources/js/service-fee.js';

const tiers = [[10000, 500], [50000, 1000], [200000, 2000], [500000, 5000]];

test('biaya bertingkat sama dengan PaymentManager::serviceFee', () => {
    assert.deepEqual(
        [0, 2000, 10000, 10001, 50000, 56000, 200000, 200001, 500000, 500001, 1000000, 1234567].map((t) => serviceFee(t, tiers, 100)),
        [0, 500, 500, 1000, 1000, 2000, 2000, 5000, 5000, 5001, 10000, 12346],
    );
});

test('di atas batas terakhir tidak kurang dari tarif tingkatan terakhir', () => {
    assert.equal(serviceFee(600000, tiers, 0), 5000);
    assert.equal(serviceFee(5000, [], 100), 50);
    assert.equal(serviceFee(5000, [], 0), 0);
});
