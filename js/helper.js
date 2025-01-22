document.addEventListener('DOMContentLoaded', function () {
    const checkbox = document.getElementById('helperRequest');
    const messageContainer = document.getElementById('helperMessageContainer');
    const messageInput = document.getElementById('helperMessage');

    checkbox.addEventListener('change', function () {
        if (checkbox.checked) {
            messageContainer.classList.remove('hidden');
            messageInput.required = true;
        } else {
            messageContainer.classList.add('hidden');
            messageInput.required = false;
        }
    });
});
