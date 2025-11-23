import google.generativeai as genai

# Paste your API key here
genai.configure(api_key="AIzaSyA5cuQra0U7oihdGNJsdxuW1qSVXlQbjTw")

# ✅ Use the latest supported model
model = genai.GenerativeModel("gemini-2.5-flash")

# Generate a simple text
response = model.generate_content("Write a haiku about AI and creativity.")
print(response.text)