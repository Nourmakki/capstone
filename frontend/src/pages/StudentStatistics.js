import React, { useCallback, useEffect, useState } from "react";
import api from "../api";
import {
    AnalyticsHeader,
    AnalyticsState,
    CHART_COLORS,
    ChartCard,
    ColumnChart,
    DonutChart,
    LineChart,
} from "../components/statistics/AnalyticsCharts";

function StudentStatistics() {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [refreshing, setRefreshing] = useState(false);

    const load = useCallback(async () => {
        setRefreshing(true);
        setError(null);
        try {
            const response = await api.get("/student/statistics");
            setData(response.data);
        } catch (err) {
            console.error(err);
            setError("Could not load your learning analytics.");
        } finally {
            setRefreshing(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    return (
        <div className="min-h-screen px-5 py-10 sm:px-8 lg:px-10 lg:py-16">
            <AnalyticsHeader
                title="My Learning Analytics"
                description="Visual analysis of your roadmap completion, learning activity, and assessment performance."
                updatedAt={data?.updated_at}
                onRefresh={load}
                refreshing={refreshing}
            />

            <AnalyticsState loading={!data && !error} error={error}>
                <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <ChartCard
                        title="Roadmap Completion Comparison"
                        description="Required modules compared with the modules you have completed in each roadmap."
                    >
                        <ColumnChart
                            items={data?.roadmap_progress ?? []}
                            series={[
                                { key: "total_nodes", label: "Total modules", color: CHART_COLORS[1] },
                                { key: "completed_nodes", label: "Completed modules", color: CHART_COLORS[0] },
                            ]}
                        />
                    </ChartCard>

                    <ChartCard
                        title="Learning Activity – Last 30 Days"
                        description="When you completed modules and tutorials or submitted projects."
                    >
                        <LineChart data={data?.activity_timeline} />
                    </ChartCard>

                    <ChartCard
                        title="Assessment Performance"
                        description="Your recorded quiz and project results, measured out of 100."
                    >
                        <ColumnChart
                            items={data?.assessment_scores ?? []}
                            maximum={100}
                            series={[
                                { key: "score", label: "Final score", color: CHART_COLORS[3] },
                            ]}
                        />
                    </ChartCard>

                    <ChartCard
                        title="Completed Learning Activities"
                        description="The composition of the work you have completed across the platform."
                    >
                        <DonutChart items={data?.learning_mix ?? []} centerLabel="Activities" />
                    </ChartCard>
                </div>
            </AnalyticsState>
        </div>
    );
}

export default StudentStatistics;
