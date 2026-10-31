<script>
(() => {
  // Urutkan baris tabel (klik judul kolom). Kolom "No"/"Urutan" yang berupa input akan dirapikan ulang setelah sort.
  document.querySelectorAll('table[data-sortable]').forEach((tabel) => {
    const ths = [...tabel.querySelectorAll('thead th[data-sort]')];
    const nilaiSel = (td, tipe) => {
      const input = td.querySelector('input[type="text"], input[type="number"]');
      const teks = (input ? input.value : (td.querySelector('.sv, a')?.textContent ?? td.textContent)).trim();
      return tipe === 'num' ? (parseInt(teks.replace(/\D/g, ''), 10) || 0) : teks.toLowerCase();
    };
    ths.forEach((th) => {
      const tombol = th.querySelector('.th-sort');
      tombol.addEventListener('click', () => {
        const kolom = [...th.parentElement.children].indexOf(th);
        const tipe = th.dataset.sort;
        const naik = th.getAttribute('aria-sort') !== 'ascending';
        ths.forEach((h) => h.removeAttribute('aria-sort'));
        th.setAttribute('aria-sort', naik ? 'ascending' : 'descending');
        const tbody = tabel.querySelector('tbody');
        const baris = [...tbody.querySelectorAll('tr')];
        baris.sort((a, b) => {
          const va = nilaiSel(a.children[kolom], tipe), vb = nilaiSel(b.children[kolom], tipe);
          const cmp = tipe === 'num' ? va - vb : va.localeCompare(vb, 'id');
          return naik ? cmp : -cmp;
        });
        baris.forEach((tr, i) => {
          tbody.appendChild(tr);
          const renomor = tabel.dataset.renomor;
          if (!renomor) { return; }
          const td = tr.children[Number(renomor)], input = td?.querySelector('input');
          if (input) { input.value = i + 1; } else if (td) { td.textContent = i + 1; }
        });
      });
    });
  });
})();
</script>
