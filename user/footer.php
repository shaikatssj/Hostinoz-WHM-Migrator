        </div>
    </div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.getElementById('sidebarToggle')?.addEventListener('click', function() {
        document.getElementById('sidebar')?.classList.add('show');
        document.getElementById('sidebarOverlay')?.classList.add('show');
    });
    document.getElementById('sidebarOverlay')?.addEventListener('click', function() {
        document.getElementById('sidebar')?.classList.remove('show');
        this.classList.remove('show');
    });
</script>
</body>
</html>
