jQuery(document).ready(function($){
    var colazBranchModal = $('#colazBranchModal');
    var colazBranchSave = $('#colazBranchSave');
    var colazChangeBranch = $('#colazChangeBranch');
    var colazBranchLocation = $('#colaz_branch_location');
    var colazCurrentBranch = $('#colazCurrentBranch');

    if (!colazBranchModal.length) {
        return;
    }

    var colazConfirmSelection = $('#colazConfirmSelection');

    // Show modal if no branch is saved or if change branch button is clicked
    if (colazBranchLocation.val() === '' || colazBranchLocation.val() === null) {
        colazBranchModal.show();
    }

    colazConfirmSelection.on('click', function() {
        colazBranchModal.hide();
    });

    colazChangeBranch.on('click', function() {
        colazBranchModal.show(); // Show the modal
        colazBranchLocation.show();
        colazBranchSave.show();
        $(this).hide();
    });

    colazBranchSave.on('click', function() {
        var branch = colazBranchLocation.val();
        if (branch) {
            $('.loader').show();
            $.ajax({
                url: wc_manager_branch_selector_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'colaz_save_branch',
                    branch_location: branch,
                    _wpnonce: wc_manager_branch_selector_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload(); // Reload the page to reflect changes
                    } else {
                        alert('Error saving branch. Please try again.');
                    }
                },
                error: function() {
                    alert('Error saving branch. Please try again.');
                },
                complete: function() {
                    $('.loader').hide();
                }
            });
        } else {
            alert('Please select a branch.');
        }
    });
});