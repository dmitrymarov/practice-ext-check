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

COPY ./plugins/helpdesk \
    /usr/src/redmine/plugins/redmine_helpdesk

COPY ./plugins/monitoring_controlling \
    /usr/src/redmine/plugins/redmine_monitoring_controlling

COPY ./plugins/additionals \
    /usr/src/redmine/plugins/additionals

RUN chown -R redmine:redmine /usr/src/redmine/plugins

RUN chown -R redmine:redmine /usr/src/redmine

USER redmine
WORKDIR /usr/src/redmine
RUN sed -i "s/i18n (~> 1\\.10\\.0)/i18n (~> 1\\.14)/" Gemfile
RUN bundle config set --local without 'development test' \
    && bundle config set --local silence_root_warning 1
RUN bundle install --jobs 4 --retry 3 \
    && bundle exec rake redmine:plugins:migrate RAILS_ENV=production