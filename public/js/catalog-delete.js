document.querySelectorAll('.delete-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var name   = this.dataset.name   || 'this item';
        var entity = this.dataset.entity ? 'Delete ' + this.dataset.entity + '?' : 'Delete?';
        SwalConfirm.fire({
            title: entity,
            html:  'You are about to delete <strong>' + name + '</strong>.<br>This cannot be undone.',
        }).then(function(result) {
            if (result.isConfirmed) form.submit();
        });
    });
});
