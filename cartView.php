<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: center;
        }
        th {
            background-color: #f4f4f4;
        }
        #error-message {
            color: red;
            margin-top: 20px;
        }
        .product-image {
            width: 50px;
            height: 50px;
            object-fit: cover;
        }
    </style>
</head>
<body>

    <h2>Shopping Cart</h2>

    <label for="userId">Enter User ID: </label>
    <input type="number" id="userId" placeholder="Enter user ID">
    <button onclick="fetchCart()">Get Cart</button>

    <p id="error-message"></p>

    <table id="cartTable" style="display: none;">
        <thead>
            <tr>
                <th>Cart ID</th>
                <th>Product Name</th>
                <th>Image</th>
                <th>Quantity</th>
                <th>Total Price</th>
            </tr>
        </thead>
        <tbody id="cartBody"></tbody>
    </table>

    <script>
        function fetchCart() {
            const userId = document.getElementById("userId").value;
            if (!userId) {
                document.getElementById("error-message").textContent = "Please enter a user ID.";
                return;
            }

            const apiUrl = `https://656a-136-158-121-206.ngrok-free.app/api/cart/cart?user_id=${userId}`;

            fetch(apiUrl)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.cart.length > 0) {
                        document.getElementById("cartTable").style.display = "table";
                        document.getElementById("error-message").textContent = "";

                        const cartBody = document.getElementById("cartBody");
                        cartBody.innerHTML = "";

                        data.cart.forEach(item => {
                            const row = `<tr>
                                <td>${item.cart_id}</td>
                                <td><strong>${item.product_name}</strong></td>
                                <td><img src="${item.product_image}" alt="${item.product_name}" class="product-image" /></td>
                                <td>${item.quantity}</td>
                                <td>$${parseFloat(item.total_price).toFixed(2)}</td>
                            </tr>`;
                            cartBody.innerHTML += row;
                        });
                    } else {
                        document.getElementById("cartTable").style.display = "none";
                        document.getElementById("error-message").textContent = "Cart not found.";
                    }
                })
                .catch(error => {
                    console.error("Error fetching cart:", error);
                    document.getElementById("error-message").textContent = "Error fetching cart data.";
                });
        }
    </script>

</body>
</html>
