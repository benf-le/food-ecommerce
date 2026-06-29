<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;


class ChatController extends Controller
{
    public function fetchMessages(Request $request)
    {
        if (Auth::check()) {
            $msgs = ChatMessage::where('user_id', Auth::id())->orderBy('created_at')->get();
        } else {
            $token = $request->cookie('chat_token');
            $msgs = $token ? ChatMessage::where('guest_token', $token)->orderBy('created_at')->get() : collect();
        }
        return response()->json($msgs);
    }

    // Send message (save user message, call AI, save bot reply)
    public function sendMessages(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $userId = Auth::id();

        //--Handler guest token (cookie)
        $guestToken = null;
        if (!$userId) {
            $guestToken = $request->cookie('chat_token');
            if (!$guestToken) {
                $guestToken = 'guest_' . Str::random(32);
                //queue cookie to return to clients (180 days)
                cookie()->queue(cookie('chat_token', $guestToken, 60 * 24 * 180));
            }
        }

        // 1) Save message user to DB
        $userMsg = ChatMessage::create([
            'user_id' => $userId,
            'guest_token' => $userId ? null : $guestToken,
            'sender' => 'user',
            'message' => $request->message
        ]);

        // 2) Prepare Prompt
        $products = Product::where('stock', '>', 0)->get(['name', 'price', 'unit', 'description'])->map(function ($p) {
            return "{$p->name} - {$p->price} / {$p->unit}";
        })->toArray();
        $productList = implode("\n", $products);

        $prompt = "
                    Bạn là trợ lý bán hàng của website thực phẩm.

                    Dưới đây là danh sách sản phẩm hiện có: 
                    $productList

                    ======================
                    QUY TẮC GHI NHỚ TÊN
                    ======================
                    - Khi người dùng nói tên của họ (ví dụ: Tôi tên Hậu, Em là Thư, Anh Gô đây):
                    → Hãy lưu lại tên đó vào bộ nhớ của bạn.
                    → Tất cả các câu trả lời sau đó phải xưng hô với đúng tên người dùng.
                    - Nếu người dùng đổi tên, hãy cập nhật tên mới và dùng tên mới để xưng hô.
                    - Khi họ hỏi: Tôi tên gì?, Tên tôi là gì? → trả lời đúng tên đã lưu.
                    - Nếu chưa có tên mà họ hỏi Tôi tên gì? → trả lời:
                    Dạ hiện mình chưa giới thiệu tên ạ, mình cho em xin tên để em tiện xưng hô hơn nha!

                    ======================
                    QUY TẮC TRẢ LỜI SẢN PHẨM
                    ======================
                    1. Trả lời ngắn gọn, rõ ràng, thân thiện theo phong cách nhân viên bán hàng.
                    2. Nếu người dùng hỏi về sản phẩm có trong danh sách:
                    - Phải lấy đúng giá từ danh sách sản phẩm.
                    - Tuyệt đối không tự tạo giá mới hoặc bịa thông số.
                    3. Nếu hỏi “ngon không?”, “tốt không?”:
                    - Trả lời theo giọng bán hàng, ví dụ:
                        “Dạ ngon lắm ạ, khách bên em mua nhiều lắm!” 
                        “Sản phẩm này chất lượng ổn định, dùng rất ok luôn ạ!”
                    4. Nếu sản phẩm không có trong danh sách:
                    - Trả lời: “Dạ hiện em chưa thấy sản phẩm này trong hệ thống ạ.”
                    5. Khi người dùng nhắc đến sản phẩm, ưu tiên trả lời thẳng vào sản phẩm (giá, unit…) và khen nó.

                    ======================
                    QUY TẮC GIAO TIẾP CHUNG
                    ======================
                    6. Nếu câu hỏi không liên quan đến sản phẩm, vẫn trả lời lịch sự, thân thiện.
                    7. Nếu người dùng tâm sự, hỏi chuyện đời tư:
                        - Phản hồi nhẹ nhàng, tôn trọng.
                        - Tìm cách dẫn dắt quay lại nội dung mua hàng khi phù hợp.
                    9. Nếu người dùng hỏi về mẹo nấu ăn, bảo quản, gợi ý món — bạn được phép trả lời tự nhiên.

                    ======================
                    GIỚI HẠN
                    ======================
                    10. Không sáng tạo giá, không bịa thông số sản phẩm.
                    11. Không được nói bạn là AI hay chatbot.
                    12. Luôn tìm cơ hội giới thiệu, gợi ý sản phẩm phù hợp.
                    ";

                    

        // Get history lasted (Exp: 6 messages ~ 3 turns user-bot)
        $history = ChatMessage::query()->where(function($q) use ($userId, $guestToken) {
            if ($userId) {
                $q->where('user_id', $userId);
            } else {
                $q->where('guest_token', $guestToken);
            }
        })
        ->latest()
        ->limit(6)
        ->orderBy('created_at', 'asc')
        ->get();

        // Change history to Suit with format gemini
        $contents = [];
        foreach ($history as $msg) {
            $contents[] = [
                "role" => $msg->sender === 'user' ? "user" : "model",
                "parts" => [["text" => $msg->message]]
            ];
        }

        //Append new message of user
        $contents[] = [
            "role" => "user",
            "parts" => [["text" => $request->message]]
        ];

        // 3) Call AI (gemini) - if haven't GOOGLE_GEMINI_API_KEY return fallback text
        $aiReplyText = "Xin lỗi, hiện tại AI chưa được cấu hình.";

        if(env('GOOGLE_GEMINI_API_KEY')){
            try {
                $url_apikey = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent";
                $payload = [
                    "systemInstruction" => [
                        "parts" => [
                            ["text" => $prompt]
                        ]
                    ],
                    "contents" => $contents
                ];

                //Call API Gemini
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Goog-Api-Key' => env('GOOGLE_GEMINI_API_KEY'),
                ])->post($url_apikey, $payload);

                // Log::info('Gemini response', [
                //     'status' => $response->status(),
                //     'body' => $response->body(),
                // ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $aiReplyText = $data['candidates'][0]['content']['parts'][0]['text']
                    ?? "Xin lỗi, tôi chưa hiểu câu hỏi.";
                } else {
                    $aiReplyText = "Xin lỗi, AI không thể xử lý lúc này.";
                    \Log::error('AI API error', ['response' => $response->json()]);
                }

            } catch (\Throwable $e) {
                \Log::error('AI call error' . $e->getMessage());
                $aiReplyText = "Xin lỗi, hiện tại không thể kết nối AI";
            }
        }

        // 4) Save bot reply
        $botMsg = ChatMessage::create([
            'user_id' => $userId,
            'guest_token' => $userId ? null : $guestToken,
            'sender' => 'bot',
            'message' => $aiReplyText,
        ]);

        // 5) Return 2 messages created (clients append)
        return response()->json([
            'user' => $userMsg,
            'bot' => $botMsg,
        ]);
    }
}
