(() => {
    const chat = document.querySelector(".chat[data-context-type]");
    if (!chat) return;

    const contextType = chat.dataset.contextType;
    const contextId = chat.dataset.contextId;
    const contextParam = contextType === "order" ? "order_id" : "transaction_id";
    const messages = chat.querySelector(".messages");
    const state = chat.querySelector(".chat-state");
    const form = chat.querySelector(".chat-form");
    const input = form.querySelector("textarea");
    const imageInput = form.querySelector('input[type="file"]');
    const submit = form.querySelector("button");
    const retry = chat.querySelector(".chat-retry");
    const currentUserId = Number(chat.dataset.currentUserId);
    const csrfToken = chat.dataset.csrfToken;
    let lastId = 0;
    let timer = null;
    let sending = false;

    function showState(text, isError = false) {
        state.textContent = text;
        state.classList.toggle("error", isError);
        state.hidden = false;
        retry.hidden = !isError;
    }

    function addMessage(message) {
        const id = Number(message.id);
        if (!Number.isSafeInteger(id) || id <= lastId) return;
        lastId = id;
        const item = document.createElement("article");
        item.className = "message" + (Number(message.sender_id) === currentUserId ? " own" : "");
        if (message.message_body !== "[image]") {
            const body = document.createElement("p");
            body.textContent = message.message_body;
            item.appendChild(body);
        }
        if (message.image_url) {
            const link = document.createElement("a");
            link.href = message.image_url;
            link.target = "_blank";
            link.rel = "noopener";
            const image = document.createElement("img");
            image.className = "chat-image";
            image.src = message.image_url;
            image.alt = "Image shared in chat";
            image.loading = "lazy";
            image.referrerPolicy = "no-referrer";
            link.appendChild(image);
            item.appendChild(link);
        }
        const meta = document.createElement("small");
        const time = document.createElement("time");
        time.dateTime = message.created_at;
        time.textContent = message.sender_name + " · " + message.created_at;
        meta.appendChild(time);
        item.appendChild(meta);
        messages.appendChild(item);
        messages.scrollTop = messages.scrollHeight;
    }

    async function loadMessages() {
        if (document.hidden) return;
        try {
            const query = new URLSearchParams({
                [contextParam]: contextId,
                after_id: String(lastId)
            });
            const response = await fetch("chat_messages.php?" + query, {
                credentials: "same-origin",
                cache: "no-store",
                headers: { "Accept": "application/json" }
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || "Messages could not be loaded.");
            result.messages.forEach(addMessage);
            showState(messages.childElementCount === 0 ? "No messages yet. Start the conversation." : "");
            if (!result.can_send) {
                input.disabled = true;
                imageInput.disabled = true;
                submit.disabled = true;
                chat.querySelector("header p").textContent = "This chat is read-only. Message history is preserved.";
            }
        } catch (error) {
            showState(error.message || "A network error prevented loading messages.", true);
        } finally {
            clearTimeout(timer);
            timer = setTimeout(loadMessages, 3000);
        }
    }

    form.addEventListener("submit", async event => {
        event.preventDefault();
        if (sending || input.disabled) return;
        const body = input.value;
        const image = imageInput.files[0];
        if (!body.trim() && !image) {
            showState("Enter a message or choose an image.", true);
            return;
        }
        sending = true;
        submit.disabled = true;
        try {
            const data = new FormData();
            data.set(contextParam, contextId);
            data.set("message_body", body);
            data.set("csrf_token", csrfToken);
            if (image) data.set("image_file", image);
            const response = await fetch("chat_messages.php", {
                method: "POST",
                credentials: "same-origin",
                headers: { "Accept": "application/json" },
                body: data
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || "Message could not be sent.");
            addMessage(result.message);
            input.value = "";
            imageInput.value = "";
            showState("");
        } catch (error) {
            showState(error.message || "A network error prevented sending the message.", true);
        } finally {
            sending = false;
            submit.disabled = input.disabled;
        }
    });

    retry.addEventListener("click", loadMessages);
    document.addEventListener("visibilitychange", () => {
        if (!document.hidden) {
            clearTimeout(timer);
            loadMessages();
        } else {
            clearTimeout(timer);
        }
    });
    loadMessages();
})();
