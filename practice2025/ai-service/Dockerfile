FROM python:3.10.16

# Set work directory
WORKDIR /app

# Create data directory for persistent storage
RUN mkdir -p /app/data

# Install system dependencies
RUN apt-get update && apt-get install -y \
    build-essential \
    libffi-dev \
    && rm -rf /var/lib/apt/lists/*

# Copy requirements file
COPY requirements.txt .

# Install Python dependencies
RUN pip install --no-cache-dir -r requirements.txt

# Copy application code
COPY app.py .

# Expose the port the app runs on
EXPOSE 5000

# Command to run the application
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "5000"]