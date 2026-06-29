$(document).ready(function () {
    $("#chat-toggle").click(function () {
        $("#chat-box").toggleClass("hidden");
        if ($("#chat-box").hasClass("hidden")) {
            $("#scrollUp").show();
            $("#chat-widget").css("bottom", "140px");
        } else {
            loadMessage();
            $("#scrollUp").hide();
            $("#chat-widget").css("bottom", "20px");
        }
    });

    $("#chat-close").click(function () {
        $("#chat-box").addClass("hidden");
        $("#chat-widget").css("bottom", "140px");
        $("#scrollUp").show();
    });

    $("#send-btn").click(function () {
        let msg = $("#message-input").val().trim();
        if (!msg) return;

        $.ajaxSetup({
            headers: {
                "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
            },
        });

        $.post("/chat/send", { message: msg }, function (res) {
            //res.user and res.bot
            if (res.user) appendOne(res.user);
            if (res.bot) appendOne(res.bot);
            $("#message-input").val("");
        }).fail(function () {
            appendOne({
                sender: "bot",
                message: "Lỗi: không gửi được tin nhắn.",
            });
        });
    });

    // Enter to send message
    $("#message-input").keypress(function (e) {
        if (e.which === 13) {
            $("#send-btn").click();
            return false;
        }
    });

    function loadMessage() {
        $("#chat-messages").html("");
        $.get("/chat/messages", function (msgs) {
            if (!msgs || msgs.length === 0) {
                $("#chat-messages").append(
                    `<div class="bot">Xin chào 👋, tôi có thể giúp gì cho bạn.</div>`
                );
                return;
            }

            msgs.forEach(function (m) {
                appendOne(m);
            });
            $("#chat-messages").scrollTop($("#chat-messages")[0].scrollHeight);
        });
    }

    // function appendOne(m) {
    //     let sender = m.sender === "user" ? "user" : "bot";

    //     // Escape HTML để tránh injection
    //     let msgHtml = escapeHtml(m.message);

    //     // 1️⃣ Chuyển newline \n thành <br>
    //     msgHtml = msgHtml.replace(/\n/g, "<br>");

    //     // 3️⃣ Chuyển các dòng dạng "- Tên sản phẩm - ..." thành bullet
    //     msgHtml = msgHtml.replace(
    //         /\s*-\s*(.+?)\s*-\s*(.+?)\s*-\s*(.+?)(<br>|$)/g,
    //         "• $1 - $2 - $3<br>"
    //     );

    //     // Append vào chat
    //     $("#chat-messages").append(`
    //     <div class="message ${sender}">
    //         ${msgHtml}
    //     </div>
    // `);

    //     // Scroll xuống cuối
    //     $("#chat-messages").scrollTop($("#chat-messages")[0].scrollHeight);
    // }

    function appendOne(m) {
        let sender = m.sender === "user" ? "user" : "bot";

        let msgHtml = m.message;

        // ✅ Chuyển **text** thành <strong>text</strong>
        msgHtml = msgHtml.replace(/\*\*(.*?)\*\*/g, "<strong>$1</strong>");

        // ✅ Nếu backend chưa có <br> thì tự động đổi \n → <br>
        msgHtml = msgHtml.replace(/\n/g, "<br>");

        // ✅ KHÔNG escape HTML (để giữ <strong>, <br>)
        // msgHtml = escapeHtml(msgHtml); ❌ KHÔNG DÙNG

        $("#chat-messages").append(`
        <div class="message ${sender}">
            ${msgHtml}
        </div>
    `);

        // Tự scroll xuống cuối
        $("#chat-messages").scrollTop($("#chat-messages")[0].scrollHeight);
    }

    function escapeHtml(text) {
        return $("<div/>").text(text).html();
    }
});
