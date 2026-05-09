    <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const selects = document.querySelectorAll('select');
            M.FormSelect.init(selects);
            const modals = document.querySelectorAll('.modal');
            M.Modal.init(modals);
            const dropdowns = document.querySelectorAll('.dropdown-trigger');
            M.Dropdown.init(dropdowns);
        });
    </script>
</body>
</html>