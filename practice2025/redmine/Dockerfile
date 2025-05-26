FROM redmine:5.0

USER root
RUN apt-get update && apt-get install -y \
    git \
    build-essential \
    libssl-dev \
    curl \
    wget \
    unzip \
    && rm -rf /var/lib/apt/lists/*

COPY ./plugins/additionals \
    /usr/src/redmine/plugins/additionals

RUN chown -R redmine:redmine /usr/src/redmine

USER redmine
WORKDIR /usr/src/redmine
RUN bundle config set --local without 'development test' \
    && bundle config set --local silence_root_warning 1
RUN bundle install --jobs 4 --retry 3