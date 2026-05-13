  </div>
</main>

<script>
  // Auto-submit any .filter-bar form when a radio (Live/Demo/All) or a select
  // (channel, mode, status, kind, source...) changes. Date inputs are excluded
  // so users can pick from/to without it submitting between them.
  (function () {
    document.querySelectorAll('form.filter-bar').forEach(function (form) {
      form.querySelectorAll('input[type="radio"], select').forEach(function (el) {
        el.addEventListener('change', function () { form.submit(); });
      });
    });
  })();
</script>
</body>
</html>
