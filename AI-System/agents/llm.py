from langchain_google_genai import ChatGoogleGenerativeAI
import os 
from dotenv import load_dotenv
from langchain_openai import ChatOpenAI

load_dotenv()
def get_gemini():
    """
    returns a model to invoke Google Gimini.
    """
    gemini_key = os.getenv("GEMINI_API_KEY")
    llm_model = ChatGoogleGenerativeAI(
        model="gemini-3.1-flash-lite",
        api_key=gemini_key,
        temperature=0,
        max_tokens=None,
        timeout=None,
        max_retries=2
    )
    return llm_model

def get_groq():
    llm = ChatOpenAI(
        model="openai/gpt-oss-20b",
        api_key=os.getenv("Groq_API_KEY"),
        base_url="https://api.groq.com/openai/v1"
    )
    return llm

llm = get_gemini()